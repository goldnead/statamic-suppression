<?php

namespace Goldnead\Suppression;

use Goldnead\Suppression\Console\ReleaseCommand;
use Goldnead\Suppression\Console\SuppressCommand;
use Goldnead\Suppression\Contracts\Gate;
use Goldnead\Suppression\Gate\DatabaseGate;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * A plain Laravel provider rather than Statamic's `AddonServiceProvider`, and
 * for the same reason brand-context is one: this package has to boot in places
 * Statamic is not — a queue worker, a console command, another package's test
 * bed. It owns no Control Panel surface, so there is nothing that would need
 * the addon base class.
 */
class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/suppression.php', 'suppression');

        $this->app->singleton('suppression', fn () => new SuppressionService);
        $this->app->alias('suppression', SuppressionService::class);

        // Bound to the contract, not the class. A host that needs to answer the
        // question from somewhere else — a shared blocklist service, a cache in
        // front of the table — swaps the binding and every send path follows,
        // as long as the replacement keeps the fail-closed promise the contract
        // spells out.
        $this->app->singleton(Gate::class, DatabaseGate::class);
        $this->app->alias(Gate::class, 'suppression.gate');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/suppression.php' => config_path('suppression.php'),
        ], 'suppression-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'suppression-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SuppressCommand::class,
                ReleaseCommand::class,
            ]);
        }
    }
}
