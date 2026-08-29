<?php

namespace Goldnead\Suppression;

use Goldnead\Suppression\Console\ReleaseCommand;
use Goldnead\Suppression\Console\SuppressCommand;
use Goldnead\Suppression\Contracts\Gate;
use Goldnead\Suppression\Gate\DatabaseGate;
use Goldnead\Suppression\Integrations\Insights\Blocked;
use Goldnead\Suppression\Integrations\Insights\Events;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Throwable;

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

        // The only translated strings this package has: the labels of the
        // figures it offers the analytics addon. They live under a namespace of
        // their own so a host can override them without owning the rest of a
        // package that has no Control Panel surface at all.
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'suppression');

        $this->registerInsightsMetrics();

        $this->publishes([
            __DIR__.'/../config/suppression.php' => config_path('suppression.php'),
        ], 'suppression-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'suppression-migrations');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/suppression'),
        ], 'suppression-translations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SuppressCommand::class,
                ReleaseCommand::class,
            ]);
        }
    }

    /**
     * The metric handles this addon contributes, and the classes behind them.
     *
     * Handle and class both, so the registry can file the class name without
     * building anything to find out what it is called — an installation with
     * twenty addons would otherwise construct every metric of every one of them
     * on a request that renders none.
     *
     * **The handles are frozen from the moment they are registered.** They end
     * up in saved dashboards and in URLs; renaming one is a breaking change.
     *
     * @var array<class-string, string>
     */
    protected const INSIGHTS_METRICS = [
        Events::class => 'suppression.events',
        Blocked::class => 'suppression.active',
    ];

    /**
     * Offer the two figures to the analytics addon, if it is there.
     *
     * From an `app->booted()` callback rather than straight from `boot()`: the
     * sibling's container bindings only exist once its own provider has booted,
     * and this one boots early on purpose — it is a foundation, and half the
     * family asks it whether an address may be mailed. Registering before the
     * sibling is up registers into nothing, silently, and produces an empty
     * screen with no error anywhere.
     *
     * **Nothing here throws, ever.** A missing, half-installed or mid-upgrade
     * analytics addon must cost a tile on a screen nobody has open — never a
     * send decision, which is what this package is for. The guards are the
     * three that have each caught a real variation of "installed but not
     * quite": the facade class may be absent, the container may refuse to build
     * the manager, and an older release of the sibling may carry the facade
     * without this method on it.
     *
     * The metric classes name the sibling's base class in their `extends`,
     * which is safe precisely because of the first guard: PHP loads a class when
     * something touches it, and nothing touches these unless the facade exists.
     * Hence `suggest` in composer.json rather than `require`.
     */
    protected function registerInsightsMetrics(): void
    {
        $this->app->booted(function (): void {
            $facade = '\Goldnead\StatamicInsights\Facades\Insights';

            if (! class_exists($facade)) {
                return;
            }

            try {
                $manager = $facade::getFacadeRoot();

                // Asked of the object, never of the facade: a facade forwards
                // through `__callStatic` and declares none of what it forwards,
                // so the probe on the facade class itself is always false.
                if (! is_object($manager) || ! method_exists($manager, 'registerMetric')) {
                    return;
                }

                foreach (self::INSIGHTS_METRICS as $class => $handle) {
                    $manager->registerMetric($class, $handle);
                }
            } catch (Throwable $e) {
                Log::warning('statamic-suppression: the insights metrics could not be registered.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }
}
