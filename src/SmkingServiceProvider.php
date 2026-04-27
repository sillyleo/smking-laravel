<?php

declare(strict_types=1);

namespace Smking\Laravel;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Smking\Laravel\Console\DoctorCommand;
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

        if ($this->app->runningInConsole()) {
            $this->commands([DoctorCommand::class]);
        }
    }

    private function registerMiddleware(): void
    {
        // Console (artisan, queue workers) would attach the middleware to the
        // wrong kernel — skip. The auto_inject gate moved into the middleware
        // itself (v0.2.0): when disabled it still emits verification headers
        // so `php artisan smking:doctor` and `curl -I` keep working.
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
