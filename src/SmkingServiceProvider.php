<?php

declare(strict_types=1);

namespace Smking\Laravel;

use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use ReflectionException;
use Smking\Laravel\Console\CachePurgeCommand;
use Smking\Laravel\Console\CircuitStatusCommand;
use Smking\Laravel\Console\DoctorCommand;
use Smking\Laravel\Console\DeliveryPrewarmCommand;
use Smking\Laravel\Console\DeliveryReportCommand;
use Smking\Laravel\Console\DeliveryWorkCommand;
use Smking\Laravel\Console\InstallCommand;
use Smking\Laravel\Console\PublishRobotsTxtCommand;
use Smking\Laravel\Delivery\CmsDeliveryNotification;
use Smking\Laravel\Delivery\DeliveryCapacity;
use Smking\Laravel\Delivery\DeliverySnapshot;
use Smking\Laravel\Delivery\DeliveryReportOutbox;
use Smking\Laravel\Delivery\DeliveryReportTransport;
use Smking\Laravel\Delivery\DeliveryTargetState;
use Smking\Laravel\Delivery\DeliveryWorklist;
use Smking\Laravel\Delivery\LegacyCmsCache;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Delivery\WaitBudget;
use Smking\Laravel\Http\Controllers\WebhookController;
use Smking\Laravel\Http\Middleware\InjectAeo;
use Smking\Laravel\Http\Middleware\TrackCrawlerHit;
use Smking\Laravel\Support\ConfigMerge;
use Smking\Laravel\Tiptap\EditorFactory;
use Smking\Laravel\View\Components\Aeo as AeoComponent;
use Smking\Laravel\View\Components\Cms as CmsComponent;
use Smking\Laravel\View\Components\Meta as MetaComponent;
use Smking\Laravel\View\Components\Runtime as RuntimeComponent;

class SmkingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Recursive merge (NOT the parent's shallow mergeConfigFrom) — a
        // customer's older published config/smking.php that predates a
        // nested default like `cms.base_path` would otherwise drop it,
        // surfacing as a null cms_base_path on heartbeat. See ConfigMerge.
        $this->mergeConfigRecursivelyFrom(__DIR__.'/../config/smking.php', 'smking');

        $deliveryCache = static function ($app) {
            $config = $app->make(\Illuminate\Contracts\Config\Repository::class);
            $cacheConfig = $config->get('smking.cache', []);
            $store = is_array($cacheConfig) ? ($cacheConfig['store'] ?? null) : null;

            return $store
                ? $app->make(\Illuminate\Contracts\Cache\Factory::class)->store($store)
                : $app->make(\Illuminate\Contracts\Cache\Factory::class)->store();
        };
        $deliveryInteger = static function ($config, string $name, int $default): int {
            $value = $config->get('smking.delivery.'.$name, $default);

            return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
        };

        $this->app->singleton(DeliveryTargetState::class, function ($app) use ($deliveryCache): DeliveryTargetState {
            return new DeliveryTargetState(
                cache: $deliveryCache($app),
                config: $app->make(\Illuminate\Contracts\Config\Repository::class),
            );
        });

        $this->app->singleton(DeliveryCapacity::class, function ($app) use ($deliveryCache): DeliveryCapacity {
            return new DeliveryCapacity(
                cache: $deliveryCache($app),
                config: $app->make(\Illuminate\Contracts\Config\Repository::class),
            );
        });

        $this->app->singleton(LegacyCmsCache::class, function ($app): LegacyCmsCache {
            return new LegacyCmsCache(
                cache: $app->make(\Illuminate\Contracts\Cache\Factory::class),
                config: $app->make(\Illuminate\Contracts\Config\Repository::class),
                capacity: $app->make(DeliveryCapacity::class),
            );
        });

        $this->app->singleton(DeliveryWorklist::class, function ($app) use ($deliveryCache, $deliveryInteger): DeliveryWorklist {
            $config = $app->make(\Illuminate\Contracts\Config\Repository::class);

            return new DeliveryWorklist(
                cache: $deliveryCache($app),
                config: $config,
                maxItems: $deliveryInteger($config, 'work_items', 100),
                heartbeatSeconds: $deliveryInteger($config, 'heartbeat_seconds', 180),
            );
        });

        $this->app->singleton(DeliveryReportOutbox::class, function ($app) use ($deliveryCache, $deliveryInteger): DeliveryReportOutbox {
            $config = $app->make(\Illuminate\Contracts\Config\Repository::class);

            return new DeliveryReportOutbox(
                cache: $deliveryCache($app),
                config: $config,
                heartbeatSeconds: $deliveryInteger($config, 'heartbeat_seconds', 180),
            );
        });

        $this->app->singleton(DeliveryReportTransport::class, function ($app): DeliveryReportTransport {
            return new DeliveryReportTransport(
                http: $app->make(\Illuminate\Http\Client\Factory::class),
                config: $app->make(\Illuminate\Contracts\Config\Repository::class),
            );
        });

        $this->app->singleton(OnDemandDelivery::class, function ($app) use ($deliveryCache): OnDemandDelivery {
            $config = $app->make(\Illuminate\Contracts\Config\Repository::class);
            $format = $config->get('smking.delivery.cache_format', DeliverySnapshot::CACHE_FORMAT);
            $format = is_int($format) || (is_string($format) && ctype_digit($format))
                ? (int) $format
                : DeliverySnapshot::CACHE_FORMAT;

            return new OnDemandDelivery(
                cache: $deliveryCache($app),
                http: $app->make(\Illuminate\Http\Client\Factory::class),
                config: $config,
                cacheFormat: $format > 0 && $format <= 100
                    ? $format
                    : DeliverySnapshot::CACHE_FORMAT,
                worklist: $app->make(DeliveryWorklist::class),
                reports: $app->make(DeliveryReportOutbox::class),
                targets: $app->make(DeliveryTargetState::class),
                capacity: $app->make(DeliveryCapacity::class),
            );
        });

        $this->app->singleton(CmsDeliveryNotification::class, function ($app): CmsDeliveryNotification {
            return new CmsDeliveryNotification(
                config: $app->make(\Illuminate\Contracts\Config\Repository::class),
                targets: $app->make(DeliveryTargetState::class),
                worklist: $app->make(DeliveryWorklist::class),
                delivery: $app->make(OnDemandDelivery::class),
                legacy: $app->make(LegacyCmsCache::class),
            );
        });

        $this->app->bind(WebhookController::class, function ($app): WebhookController {
            return new WebhookController(
                config: $app->make(\Illuminate\Contracts\Config\Repository::class),
                cache: $app->make(\Illuminate\Contracts\Cache\Factory::class),
                aeoCacheInvalidator: $app->make(\Smking\Laravel\Support\AeoCacheInvalidator::class),
                logger: $app->bound(\Psr\Log\LoggerInterface::class)
                    ? $app->make(\Psr\Log\LoggerInterface::class)
                    : null,
                cmsCache: $app->make(LegacyCmsCache::class),
                delivery: $app->make(OnDemandDelivery::class),
                deliveryNotification: $app->make(CmsDeliveryNotification::class),
            );
        });

        $this->app->scoped(WaitBudget::class, function ($app): WaitBudget {
            $value = $app['config']->get('smking.delivery.page_budget_ms', 500);
            $milliseconds = is_int($value) || (is_string($value) && ctype_digit($value))
                ? (int) $value
                : 0;

            return new WaitBudget(max(0, min(2000, $milliseconds)));
        });

        $this->app->singleton(AeoClient::class, function ($app): AeoClient {
            return new AeoClient(
                http: $app->make(\Illuminate\Http\Client\Factory::class),
                cache: $app->make(\Illuminate\Contracts\Cache\Factory::class),
                config: $app->make(\Illuminate\Contracts\Config\Repository::class),
                logger: $app->bound(\Psr\Log\LoggerInterface::class)
                    ? $app->make(\Psr\Log\LoggerInterface::class)
                    : null,
                delivery: $app->make(OnDemandDelivery::class),
                deliveryBudget: static fn (): WaitBudget => $app->make(WaitBudget::class),
            );
        });

        $this->app->alias(AeoClient::class, 'smking.aeo');

        // CMS — singleton EditorFactory (extension instances reused per
        // request) + CmsClient that depends on it. Same auth / cache /
        // logger wiring as AeoClient.
        $this->app->singleton(EditorFactory::class);

        $this->app->singleton(CmsClient::class, function ($app): CmsClient {
            return new CmsClient(
                http: $app->make(\Illuminate\Http\Client\Factory::class),
                cache: $app->make(\Illuminate\Contracts\Cache\Factory::class),
                config: $app->make(\Illuminate\Contracts\Config\Repository::class),
                editor: $app->make(EditorFactory::class),
                logger: $app->bound(\Psr\Log\LoggerInterface::class)
                    ? $app->make(\Psr\Log\LoggerInterface::class)
                    : null,
                delivery: $app->make(OnDemandDelivery::class),
                deliveryBudget: static fn (): WaitBudget => $app->make(WaitBudget::class),
                legacyCache: $app->make(LegacyCmsCache::class),
            );
        });

        $this->app->alias(CmsClient::class, 'smking.cms');
    }

    /**
     * Recursive variant of {@see ServiceProvider::mergeConfigFrom}. The
     * built-in only array_merges the TOP level of the config tree, so a
     * customer's published config that omits a newly-added nested default
     * (an older `cms` section without `base_path`, say) wipes that default
     * wholesale. ConfigMerge::deep walks associative arrays so package
     * defaults survive unless the customer set that exact leaf. Mirrors the
     * parent's config-cache guard (cached config already holds the result).
     */
    protected function mergeConfigRecursivelyFrom(string $path, string $key): void
    {
        if (
            $this->app instanceof CachesConfiguration
            && $this->app->configurationIsCached()
        ) {
            return;
        }

        $config = $this->app->make('config');
        $config->set(
            $key,
            ConfigMerge::deep(require $path, $config->get($key, [])),
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/smking.php' => $this->app->configPath('smking.php'),
        ], 'smking-config');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/smking'),
        ], 'smking-views');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'smking');

        $this->loadViewComponentsAs('smking', [
            'aeo' => AeoComponent::class,
            'cms' => CmsComponent::class,
            'meta' => MetaComponent::class,
            'runtime' => RuntimeComponent::class,
        ]);

        $this->registerMiddleware();
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
                DeliveryPrewarmCommand::class,
                DeliveryReportCommand::class,
                DeliveryWorkCommand::class,
                CachePurgeCommand::class,
                CircuitStatusCommand::class,
                InstallCommand::class,
                PublishRobotsTxtCommand::class,
            ]);
        }
    }

    /**
     * Auto-mount SDK-provided routes for the unconfigured case:
     *   - `/api/smking/webhook` — inbound HMAC-verified publish notifications
     *     from SmKing SaaS (gated by `smking.webhook.enabled`, default true)
     *   - `/sitemap.xml`        — SaaS-served sitemap.xml takeover (gated by
     *     `smking.takeover.sitemap`, default true)
     *   - `/llms.txt`           — SaaS-served llms.txt takeover (gated by
     *     `smking.takeover.llms_txt`, default true)
     *
     * Production `route:cache` mode: service-provider auto-mount is skipped
     * because Laravel doesn't re-run `boot()` after a route cache file
     * exists. The wizard registers `require base_path('vendor/smking/laravel/routes/{webhook,takeover}.php')`
     * in customer's route files so `route:cache` picks the routes up like
     * any user-defined route (audit handoff #7 fix). Double-registration is
     * idempotent — Laravel's route collection dedupes by name + method + URI.
     *
     * Per-route opt-out lives in `config/smking.php`. Customers who already
     * own `/sitemap.xml` or `/llms.txt` should flip the matching flag to
     * `false` rather than relying on route precedence.
     */
    private function registerRoutes(): void
    {
        if (! method_exists($this->app, 'routesAreCached') || $this->app->routesAreCached()) {
            // Production route cache present — auto-mount is skipped here.
            // The wizard's `require` calls in customer route files cover this
            // path so cached routes include the SDK ones.
            return;
        }

        $webhookEnabled = (bool) $this->app['config']->get('smking.webhook.enabled', true);
        if ($webhookEnabled) {
            require __DIR__.'/../routes/webhook.php';
        }

        // takeover.php is self-gating per-path: it reads
        // smking.takeover.sitemap / smking.takeover.llms_txt internally and
        // skips the Route::get when false. No outer guard needed.
        require __DIR__.'/../routes/takeover.php';

        // preview.php is self-gating (reads smking.preview.enabled). CMS
        // draft preview entry; harmless + fail-safe when no token is present.
        require __DIR__.'/../routes/preview.php';
    }

    private function registerMiddleware(): void
    {
        /** @var HttpKernel $kernel */
        $kernel = $this->app->make(HttpKernel::class);

        if (! method_exists($kernel, 'pushMiddleware')) {
            return;
        }

        // v0.2.1: register on console too. pushMiddleware just mutates the
        // HTTP kernel's protected $middleware array; the Console kernel
        // (which actually runs `php artisan …`) is a separate instance with
        // its own middleware stack, so this has no runtime side-effect on
        // console commands. The win: `php artisan smking:doctor` can now
        // reflect into the array and confirm InjectAeo is wired up.
        $stack = $this->loadMiddlewareStack($kernel);
        if ($stack === null) {
            return;
        }

        if (! in_array(InjectAeo::class, $stack, true)) {
            $kernel->pushMiddleware(InjectAeo::class);
        }

        // TrackCrawlerHit (v0.12+) — terminable middleware, fires AFTER the
        // response is sent. Safe to push alongside InjectAeo because it's
        // pure read-only (never touches the response body).
        if (! in_array(TrackCrawlerHit::class, $stack, true)) {
            $kernel->pushMiddleware(TrackCrawlerHit::class);
        }
    }

    /**
     * Idempotency helper — read the kernel's middleware array via reflection
     * so we can skip pushing what's already wired up (test harnesses /
     * double-boot scenarios). Returns null when reflection isn't possible.
     *
     * @return list<class-string>|null
     */
    private function loadMiddlewareStack(HttpKernel $kernel): ?array
    {
        if (! $kernel instanceof FoundationKernel) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($kernel);
            $property = $reflection->getProperty('middleware');
            $property->setAccessible(true);
            /** @var array<int, class-string> $middleware */
            $middleware = (array) $property->getValue($kernel);

            return array_values($middleware);
        } catch (ReflectionException) {
            return null;
        }
    }
}
