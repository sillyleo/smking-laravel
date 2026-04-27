<?php

declare(strict_types=1);

namespace Smking\Laravel;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Smking\Laravel\Http\Middleware\InjectAeo;
use Smking\Laravel\View\Components\Aeo as AeoComponent;
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
            'meta' => MetaComponent::class,
        ]);

        $this->registerMiddleware();
    }

    private function registerMiddleware(): void
    {
        if (! (bool) $this->app['config']->get('smking.auto_inject', true)) {
            return;
        }

        // Only auto-register when running in HTTP context. Console (artisan,
        // queue workers) would attach the middleware to the wrong kernel.
        if ($this->app->runningInConsole()) {
            return;
        }

        /** @var HttpKernel $kernel */
        $kernel = $this->app->make(HttpKernel::class);

        if (method_exists($kernel, 'pushMiddleware')) {
            $kernel->pushMiddleware(InjectAeo::class);
        }
    }
}
