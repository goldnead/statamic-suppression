<?php

use Illuminate\Support\Facades\DB;

/**
 * The guard this family adopted after an unbuildable index reached production.
 *
 * InnoDB refuses any index wider than 3072 bytes, and under utf8mb4 a
 * `varchar(255)` costs 1020 of them. A four-column unique over such columns is
 * over the limit and MySQL rejects the whole table with SQLSTATE 1071 — while
 * the SQLite suite stays green throughout, because SQLite has no key limit, no
 * per-character byte cost and no fixed column widths. The arithmetic that fails
 * on MySQL does not exist there to be tested.
 *
 * So this test does not ask a database. It compiles this package's own
 * migrations through Laravel's MySQL grammar in pretend mode — no server, no
 * connection, nothing to install in CI — and measures the DDL MySQL would have
 * received. It reads the real migration files, so it cannot drift from them.
 *
 * The concrete budget here: `email_normalized` is a `varchar(255)` at 1020
 * bytes, which appears in both of this package's widest indexes. That is what
 * decided `dedupe_key` to be a `varchar(64)` rather than the default — a
 * 40-character sha1 does not need 255, and the second assertion below is what
 * would have caught it costing 1020 for no reason.
 */
const SUPPRESSION_INNODB_MAX_KEY_BYTES = 3072;

it('keeps every index the migrations create inside the InnoDB key limit', function (): void {
    $schema = compileSuppressionMigrationsForMysql();

    expect($schema['indexes'])->not->toBeEmpty();

    foreach ($schema['indexes'] as $index) {
        $bytes = 0;

        foreach ($index['columns'] as $column) {
            $width = $schema['columns'][$index['table']][$column] ?? null;

            expect($width)->not->toBeNull("Index {$index['name']} covers unknown column {$column}.");

            $bytes += $width;
        }

        expect($bytes)->toBeLessThanOrEqual(
            SUPPRESSION_INNODB_MAX_KEY_BYTES,
            "Index {$index['name']} on {$index['table']} needs {$bytes} bytes under utf8mb4; ".
            'InnoDB allows '.SUPPRESSION_INNODB_MAX_KEY_BYTES.'. MySQL would refuse this migration with SQLSTATE 1071.'
        );
    }
});

it('still spends less than half the key limit, leaving room for another column', function (): void {
    // Being under the limit by accident is what makes a schema fragile: the
    // next column added to an index that already sits at 90% breaks a deploy
    // rather than a test.
    $schema = compileSuppressionMigrationsForMysql();

    foreach ($schema['indexes'] as $index) {
        $bytes = collect($index['columns'])->sum(fn ($column) => $schema['columns'][$index['table']][$column] ?? 0);

        expect($bytes)->toBeLessThan(
            SUPPRESSION_INNODB_MAX_KEY_BYTES / 2,
            "Index {$index['name']} on {$index['table']} uses {$bytes} bytes — over half the limit, ".
            'so the next column added to it is likely to break the migration.'
        );
    }
});

it('expresses the scope boundary in the unique rather than beside it', function (): void {
    $schema = compileSuppressionMigrationsForMysql();

    $uniques = collect($schema['indexes'])->where('unique', true)->keyBy('name');

    // brand_id first, and in the index rather than filtered in PHP: 0 and a
    // real brand id are different rows, and the database is what has to say so.
    expect($uniques['supp_brand_email_unique']['columns'])->toBe(['brand_id', 'email_normalized']);

    // The dedupe unique stands alone on purpose. It is global across brands
    // because a provider event id is global across brands.
    expect($uniques['suppev_dedupe_unique']['columns'])->toBe(['dedupe_key']);
});

it('keeps brand_id non-nullable, because a unique does not bind NULL', function (): void {
    $definitions = compileSuppressionMigrationsForMysql()['definitions'];

    foreach (['suppressions', 'suppression_events'] as $table) {
        expect(str_contains($definitions[$table]['brand_id'], 'not null'))->toBeTrue(
            "{$table}.brand_id must be NOT NULL: MySQL treats NULLs as distinct inside a unique index, ".
            'so a nullable brand_id would silently permit duplicate global rows.'
        );

        // And it has to default to the global scope, or every writer that
        // forgets to name a brand writes a row nobody can find.
        expect(str_contains($definitions[$table]['brand_id'], "default '0'"))->toBeTrue(
            "{$table}.brand_id must default to 0, the value that means 'every brand'."
        );
    }

    // The counterpart: the dedupe key is nullable on purpose, so that manual
    // events — which have nothing to key on — are not forced to invent one and
    // collide with each other.
    expect(str_contains($definitions['suppression_events']['dedupe_key'], 'not null'))->toBeFalse(
        'dedupe_key must stay nullable: a manual action has no provider event to deduplicate against, '.
        'and forcing a key on it would make a second legitimate release look like a redelivery of the first.'
    );
});

/**
 * Runs every migration in the package against a MySQL connection that is never
 * opened, and returns the column widths, raw definitions and index definitions
 * MySQL would see.
 *
 * @return array{columns: array<string, array<string, int>>, definitions: array<string, array<string, string>>, indexes: list<array{table: string, name: string, unique: bool, columns: list<string>}>}
 */
function compileSuppressionMigrationsForMysql(): array
{
    config()->set('database.connections.suppression_key_probe', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'suppression_key_probe',
        'username' => 'probe',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);

    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('suppression_key_probe');

    try {
        // pretend() short-circuits every statement before a PDO instance is
        // needed, so this compiles the DDL with no server anywhere in sight.
        $queries = DB::connection('suppression_key_probe')->pretend(function (): void {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
                (require $file)->up();
            }
        });
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('suppression_key_probe');
    }

    $columns = [];
    $definitions = [];
    $indexes = [];

    foreach (array_column($queries, 'query') as $sql) {
        if (preg_match('/^create table `(\w+)` \((.*)\)(?: default character set| collate|$)/s', $sql, $match)) {
            foreach (splitSuppressionTopLevel($match[2]) as $definition) {
                if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = suppressionMysqlIndexBytes($column[2]);
                    $definitions[$match[1]][$column[1]] = $column[2];
                }
            }

            continue;
        }

        if (preg_match('/^alter table `(\w+)` add (unique|index) `(\w+)`\((.+)\)$/', $sql, $match)) {
            $indexes[] = [
                'table' => $match[1],
                'name' => $match[3],
                'unique' => $match[2] === 'unique',
                'columns' => array_map(fn ($column) => trim($column, ' `'), explode(',', $match[4])),
            ];
        }
    }

    return ['columns' => $columns, 'definitions' => $definitions, 'indexes' => $indexes];
}

/** Splits a column list on commas that are not inside parentheses. */
function splitSuppressionTopLevel(string $list): array
{
    $parts = [];
    $depth = 0;
    $buffer = '';

    foreach (str_split($list) as $character) {
        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        }

        if ($character === ',' && $depth === 0) {
            $parts[] = $buffer;
            $buffer = '';

            continue;
        }

        $buffer .= $character;
    }

    return array_merge($parts, [$buffer]);
}

/** Worst-case bytes this column type occupies in an index under utf8mb4. */
function suppressionMysqlIndexBytes(string $type): int
{
    if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $match)) {
        return (int) $match[1] * 4;
    }

    return match (true) {
        str_starts_with($type, 'tinyint') => 1,
        str_starts_with($type, 'smallint') => 2,
        str_starts_with($type, 'mediumint') => 3,
        str_starts_with($type, 'int') => 4,
        str_starts_with($type, 'bigint') => 8,
        str_starts_with($type, 'timestamp'), str_starts_with($type, 'datetime') => 8,
        str_starts_with($type, 'date') => 3,
        // Blobs and JSON cannot be indexed whole at all. Reported as oversized
        // so an index that reaches for one fails here rather than on MySQL.
        default => SUPPRESSION_INNODB_MAX_KEY_BYTES + 1,
    };
}
