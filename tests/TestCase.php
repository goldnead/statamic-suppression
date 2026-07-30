<?php

namespace Goldnead\Suppression\Tests;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Suppression\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            \Goldnead\BrandContext\ServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'BrandContext' => \Goldnead\BrandContext\Facades\BrandContext::class,
            'Suppression' => \Goldnead\Suppression\Facades\Suppression::class,
            'SuppressionGate' => \Goldnead\Suppression\Facades\SuppressionGate::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());

        // Default to single-brand; the cross-brand tests flip it on. The
        // suppression tables are not brand-scoped by Eloquent at all, so this
        // flag changes what `currentId()` answers, not what the gate sees.
        $app['config']->set('brand-context.multi_brand', false);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('mail.default', 'array');
    }

    /**
     * In-memory SQLite by default, so the suite keeps running anywhere with no
     * setup. Set `DB_DRIVER=mysql` to point the identical suite at a real MySQL
     * server instead — see phpunit.mysql.xml.
     *
     * SQLite is not a substitute for that run, and it matters here more than in
     * most siblings. This package's whole correctness argument rests on two
     * index behaviours SQLite expresses differently from InnoDB: a unique over
     * a NOT NULL `brand_id` that must bite, and a unique over a nullable
     * `dedupe_key` that must not. `tests/Unit/IndexKeyLengthTest.php` measures
     * the compiled DDL without a server; this measures the engine.
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'suppression_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    /**
     * Multi-brand on. `forget()` is not optional — the manager is a singleton
     * that memoizes both the current and the default brand.
     */
    protected function enableMultiBrand(): void
    {
        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();
    }

    protected function makeBrand(string $handle): Brand
    {
        return Brand::query()->firstOrCreate(
            ['handle' => $handle],
            ['name' => ucfirst($handle)]
        );
    }

    /** Run a closure with a given brand current, then restore. */
    protected function inBrand(string $handle, \Closure $callback): mixed
    {
        return app('brand-context')->runFor($handle, $callback);
    }
}
