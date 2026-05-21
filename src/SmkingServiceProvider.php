<?php

declare(strict_types=1);

namespace Smking\Laravel;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use ReflectionException;
use Smking\Laravel\Console\CachePurgeCommand;
use Smking\Laravel\Console\CircuitStatusCommand;
use Smking\Laravel\Console\DoctorCommand;
use Smking\Laravel\Console\InstallCommand;
use Smking\Laravel\Console\PublishRobotsTxtCommand;
use Smking\Laravel\Http\Controllers\WebhookController;
use Smking\Laravel\Http\Middleware\InjectAeo;
use Smking\Laravel\Http\Middleware\TrackCrawlerHit;
use Smking\Laravel\Tiptap\EditorFactory;
use Smking\Laravel\View\Components\Aeo as AeoComponent;
use Smking\Laravel\View\Components\Cms as CmsComponent;
use Smking\Laravel\View\Components\Meta as MetaComponent;

class SmkingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/smking.php', 'smking');

        $this->app->singleton(AeoClient::class, function ($app): AeoClient {
            return new AeoClient(
                http: $app->make(\Illuminate\Http\Client\Factory::class),
                cache: $app->make(\Illuminate\Contracts\Cache\Factory::class),
                config: $app->make(\Illuminate\Contracts\Config\Repository::class),
                logger: $app->bound(\Psr\Log\LoggerInterface::class)
                    ? $app->make(\Psr\Log\LoggerInterface::class)
                    : null,
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
            );
        });

        $this->app->alias(CmsClient::class, 'smking.cms');
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
        ]);

        $this->registerMiddleware();
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
                CachePurgeCommand::class,
                CircuitStatusCommand::class,
                InstallCommand::class,
                PublishRobotsTxtCommand::class,
            ]);
        }
    }

    /**
     * Auto-mount the inbound webhook receiver at /api/smking/webhook.
     * Customer pastes that URL into the SmKing dashboard's webhook field;
     * SaaS POSTs publish events here, we HMAC-verify, evict CmsClient
     * cache. Single endpoint serves all future event types — branched
     * inside the controller — so the wire interface stays small.
     *
     * Disable per config (`smking.webhook.enabled = false`) for customers
     * who'd rather mount it manually on a custom path / domain.
     */
    private function registerRoutes(): void
    {
        $enabled = (bool) ($this->app['config']->get('smking.webhook.enabled', true));
        if (! $enabled) {
            return;
        }

        if (! method_exists($this->app, 'routesAreCached') || $this->app->routesAreCached()) {
            // Production route cache present — auto-mount is skipped here.
            // smking-wizard registers `require base_path('vendor/smking/laravel/routes/webhook.php')`
            // in customer's routes/api.php so route:cache picks it up like
            // any user-defined route (audit handoff #7 fix).
            return;
        }

        // Auto-mount for unconfigured installs (no wizard run, no route:cache).
        // Once wizard runs, customer's routes/api.php require produces the
        // same Route::post — Laravel dedupes by name + method + URI.
        require __DIR__.'/../routes/webhook.php';
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
