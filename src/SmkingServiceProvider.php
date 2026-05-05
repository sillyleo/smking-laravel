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
use Smking\Laravel\Console\PublishRobotsTxtCommand;
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
            $this->commands([
                DoctorCommand::class,
                CachePurgeCommand::class,
                CircuitStatusCommand::class,
                PublishRobotsTxtCommand::class,
            ]);
        }
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
        if ($this->isMiddlewareRegistered($kernel)) {
            return;
        }

        $kernel->pushMiddleware(InjectAeo::class);
    }

    /**
     * Idempotency check — avoid double-pushing when service providers boot
     * twice (rare but possible in test harnesses or custom dev setups).
     */
    private function isMiddlewareRegistered(HttpKernel $kernel): bool
    {
        if (! $kernel instanceof FoundationKernel) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($kernel);
            $property = $reflection->getProperty('middleware');
            $property->setAccessible(true);
            /** @var array<int, class-string> $middleware */
            $middleware = (array) $property->getValue($kernel);
        } catch (ReflectionException) {
            return false;
        }

        return in_array(InjectAeo::class, $middleware, true);
    }
}
