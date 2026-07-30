<?php

namespace Goldnead\Suppression\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bed for migrating a database by hand, against tables that already hold rows.
 *
 * The rest of the suite meets a database `RefreshDatabase` has already migrated
 * to head, which is the one shape a migration can never be wrong about.
 * Everything here needs the opposite: an empty database, rows put in, and the
 * migrations run one file at a time with the tables no longer empty.
 *
 * That cannot share the suite's connection. `RefreshDatabase` wraps every test
 * in a transaction and DDL under MySQL commits implicitly — a `migrate` inside
 * that transaction would end it and leak tables into every following test. So
 * these get a connection of their own: a temp-file SQLite database by default,
 * and a throwaway schema beside the configured one under MySQL.
 *
 * Copied in shape from statamic-marketing, where the apparatus was built after
 * a released migration died halfway through on an install that had data in it
 * and left the consent unique dropped and not replaced. This package is a gate:
 * a migration of its that aborts on real data leaves a host with no gate at
 * all, which is worse than never having installed one.
 */
abstract class MigrationPathTestCase extends TestCase
{
    protected const CONNECTION = 'migration_path';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->dropIsolatedSqliteFile();

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.'.self::CONNECTION, $this->isolatedConnection());

        // A server-level handle with no database selected, used for nothing but
        // `create database`. Issuing that on the suite's own connection would
        // implicitly commit the transaction RefreshDatabase holds open.
        $app['config']->set('database.connections.'.self::CONNECTION.'_server', [
            ...$this->isolatedConnection(),
            'database' => null,
        ]);
    }

    protected function isolatedConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => $this->isolatedSqlitePath(),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'suppression_test').'_migration_path',
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    protected function isolatedSqlitePath(): string
    {
        return sys_get_temp_dir().'/suppression-migration-path-'.getmypid().'.sqlite';
    }

    /**
     * Empty database with brand-context installed and nothing of this package's.
     *
     * `brands` is a precondition rather than decoration: a host running this
     * package always has brand-context under it, and a scope test that invents
     * brand ids instead of reading them proves nothing about the real thing.
     */
    protected function resetIsolatedDatabase(): void
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            $this->dropIsolatedSqliteFile();
            touch($this->isolatedSqlitePath());
        } else {
            $database = env('DB_DATABASE', 'suppression_test').'_migration_path';

            DB::connection(self::CONNECTION.'_server')->statement(
                'create database if not exists `'.$database.'` character set utf8mb4 collate utf8mb4_unicode_ci'
            );

            DB::purge(self::CONNECTION.'_server');
        }

        DB::purge(self::CONNECTION);

        Schema::connection(self::CONNECTION)->dropAllTables();

        DB::purge(self::CONNECTION);

        $this->migratePath(__DIR__.'/../vendor/goldnead/statamic-brand-context/database/migrations');
    }

    protected function dropIsolatedSqliteFile(): void
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            DB::purge(self::CONNECTION);

            if (file_exists($this->isolatedSqlitePath())) {
                @unlink($this->isolatedSqlitePath());
            }
        }
    }

    /**
     * Run every not-yet-run migration in a directory. Failures are not
     * swallowed: the point of these tests is what happens when one throws.
     */
    protected function migratePath(string $path): void
    {
        Artisan::call('migrate', [
            '--database' => self::CONNECTION,
            '--path' => $path,
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    /**
     * Run the migrations one file at a time, handing control back between each
     * so a caller can put rows in first.
     *
     * @param  callable(string): void|null  $before  receives the migration name
     */
    protected function migrateStepwise(string $path, ?callable $before = null): void
    {
        foreach ($this->migrationFilesIn($path) as $file) {
            if ($before) {
                $before(basename($file, '.php'));
            }

            $this->migratePath($file);
        }
    }

    /** @return list<string> */
    protected function migrationFilesIn(string $path): array
    {
        $files = glob(rtrim($path, '/').'/*.php') ?: [];

        sort($files);

        return $files;
    }

    protected function currentMigrations(): string
    {
        return __DIR__.'/../database/migrations';
    }

    protected function isolated(): \Illuminate\Database\Connection
    {
        return DB::connection(self::CONNECTION);
    }

    protected function isolatedSchema(): \Illuminate\Database\Schema\Builder
    {
        return Schema::connection(self::CONNECTION);
    }

    /** @return list<string> */
    protected function ranMigrations(): array
    {
        if (! $this->isolatedSchema()->hasTable('migrations')) {
            return [];
        }

        return $this->isolated()->table('migrations')->pluck('migration')->all();
    }

    /**
     * Whether the database accepts a second row for a (brand, address) pair
     * that already has one.
     *
     * Deliberately behavioural. "The migration ran" and "the constraint is
     * there" are not the same statement, and an index by name can exist over
     * the wrong columns, over a nullable column, or not bite at all. The only
     * thing that settles it is writing the row the constraint is supposed to
     * refuse and requiring the database to refuse it.
     */
    protected function duplicateSuppressionIsAccepted(int $brandId, string $email): bool
    {
        $existing = $this->isolated()->table('suppressions')
            ->where('brand_id', $brandId)
            ->where('email_normalized', $email)
            ->first();

        if (! $existing) {
            throw new \RuntimeException("No suppression for [{$email}] in brand [{$brandId}] to duplicate.");
        }

        $row = collect((array) $existing)
            ->except('id')
            ->put('uuid', (string) \Illuminate\Support\Str::uuid())
            ->all();

        try {
            $id = $this->isolated()->table('suppressions')->insertGetId($row);
        } catch (\Illuminate\Database\QueryException) {
            return false;
        }

        $this->isolated()->table('suppressions')->where('id', $id)->delete();

        return true;
    }
}
