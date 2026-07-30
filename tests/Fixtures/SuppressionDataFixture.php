<?php

namespace Goldnead\Suppression\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rows for the migration bed, written against whatever schema exists right now.
 *
 * It asks the schema what it has rather than naming columns, so it can be
 * called repeatedly *between* migrations — each migration then meets rows that
 * were written under the schema as it stood a moment earlier, which is the only
 * arrangement that catches a migration that is wrong about existing data.
 *
 * Unknown NOT NULL columns get a generic unique value, on the theory that a new
 * column tends to ship with a unique over it. That is precisely the case that
 * should trip a test rather than a deploy.
 */
class SuppressionDataFixture
{
    public function __construct(protected Connection $connection) {}

    /**
     * Seed one generation of rows. Batch 0 is verbatim so assertions can name
     * it; later batches are suffixed so repeat calls never collide.
     *
     * @return int number of suppression rows written
     */
    public function seed(int $batch = 0): int
    {
        $suffix = $batch === 0 ? '' : "-b{$batch}";
        $written = 0;

        foreach ($this->suppressionRows($suffix) as $row) {
            if ($this->insert('suppressions', $row)) {
                $written++;
            }
        }

        foreach ($this->eventRows($suffix) as $row) {
            $this->insert('suppression_events', $row);
        }

        return $written;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = [];

        foreach (['suppressions', 'suppression_events'] as $table) {
            if ($this->has($table)) {
                $counts[$table] = $this->connection->table($table)->count();
            }
        }

        return $counts;
    }

    /** The (brand, address) pair a uniqueness probe should use for a batch. */
    public static function uniqueProbe(int $batch = 0): array
    {
        $suffix = $batch === 0 ? '' : "-b{$batch}";

        return ['brand_id' => 0, 'email' => "bounced{$suffix}@example.test"];
    }

    protected function suppressionRows(string $suffix): array
    {
        $now = now();

        return [
            [
                'uuid' => (string) Str::uuid(),
                'brand_id' => 0,
                'email_normalized' => "bounced{$suffix}@example.test",
                'reason' => 'hard_bounce',
                'source' => 'backfill',
                'suppressed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'brand_id' => 1,
                'email_normalized' => "complained{$suffix}@example.test",
                'reason' => 'complaint',
                'source' => 'backfill',
                'suppressed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'brand_id' => 1,
                'email_normalized' => "released{$suffix}@example.test",
                'reason' => 'manual',
                'source' => 'cp',
                'suppressed_at' => $now,
                'released_at' => $now,
                'released_by' => 'user:1',
                'release_reason' => 'Requested by the recipient in writing, ticket 4711.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];
    }

    protected function eventRows(string $suffix): array
    {
        $now = now();

        return [
            [
                'uuid' => (string) Str::uuid(),
                'brand_id' => 0,
                'email_normalized' => "bounced{$suffix}@example.test",
                'event_type' => 'suppressed',
                'reason' => 'hard_bounce',
                'source' => 'backfill',
                'dedupe_key' => sha1('fixture:bounced'.$suffix),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            // Two manual events with no dedupe key at all. They must both be
            // accepted — a unique does not bind NULL, and that is the whole
            // reason manual actions carry no key.
            [
                'uuid' => (string) Str::uuid(),
                'brand_id' => 1,
                'email_normalized' => "released{$suffix}@example.test",
                'event_type' => 'released',
                'reason' => 'manual',
                'actor' => 'user:1',
                'dedupe_key' => null,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'brand_id' => 1,
                'email_normalized' => "released{$suffix}@example.test",
                'event_type' => 'suppressed',
                'reason' => 'manual',
                'actor' => 'user:1',
                'dedupe_key' => null,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];
    }

    protected function has(string $table): bool
    {
        return Schema::connection($this->connection->getName())->hasTable($table);
    }

    /** @return array<string> */
    protected function columns(string $table): array
    {
        return Schema::connection($this->connection->getName())->getColumnListing($table);
    }

    protected function insert(string $table, array $row): bool
    {
        if (! $this->has($table)) {
            return false;
        }

        $columns = $this->columns($table);
        $reduced = array_intersect_key($row, array_flip($columns));

        // Anything NOT NULL this fixture has never heard of gets a value, so a
        // newly added column is exercised rather than silently skipped.
        foreach ($this->requiredUnknownColumns($table, $columns, $reduced) as $column) {
            $reduced[$column] = $this->genericValueFor($column);
        }

        $this->connection->table($table)->insert($reduced);

        return true;
    }

    /** @return list<string> */
    protected function requiredUnknownColumns(string $table, array $columns, array $reduced): array
    {
        $schema = Schema::connection($this->connection->getName());

        $missing = [];

        foreach ($columns as $column) {
            if (array_key_exists($column, $reduced) || $column === 'id') {
                continue;
            }

            $definition = collect($schema->getColumns($table))->firstWhere('name', $column);

            if ($definition && ($definition['nullable'] ?? true) === false && ($definition['default'] ?? null) === null) {
                $missing[] = $column;
            }
        }

        return $missing;
    }

    protected function genericValueFor(string $column): string
    {
        return substr($column.'-'.Str::random(12), 0, 32);
    }
}
