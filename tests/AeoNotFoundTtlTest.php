<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Support\ConfigMerge;

class AeoNotFoundTtlTest extends TestCase
{
    public static function configurations(): iterable
    {
        foreach (['aeo', 'markdown', 'public_404', 'public_transport_error'] as $surface) {
            foreach (['default', 'missing', 'merged', 'null', 'custom', 'numeric_string', 'zero', 'capped'] as $variant) {
                yield "$surface/$variant" => [$surface, $variant];
            }
        }
    }

    #[DataProvider('configurations')]
    public function test_write_ttl_uses_the_same_default_as_sdk_report(string $surface, string $variant): void
    {
        $defaults = require __DIR__.'/../config/smking.php';
        $options = $defaults['cache'];
        $options['enabled'] = true;
        $options['store'] = 'array';
        unset($options['not_found_ttl']);
        $expected = 60;
        if ($variant === 'default') {
            $options['not_found_ttl'] = $defaults['cache']['not_found_ttl'];
        } elseif ($variant === 'merged') {
            $options = ConfigMerge::deep($defaults, ['cache' => $options])['cache'];
        } elseif ($variant === 'null') {
            $options['not_found_ttl'] = null;
        } elseif (in_array($variant, ['custom', 'numeric_string', 'zero'], true)) {
            $expected = $variant === 'zero' ? 0 : 30;
            $options['not_found_ttl'] = $variant === 'numeric_string' ? '30' : $expected;
        } elseif ($variant === 'capped') {
            $options['ttl'] = 15;
            $options['takeover_ttl'] = 15;
        }
        config()->set('smking.cache', $options);

        $repository = new class(new ArrayStore()) extends CacheRepository {
            /** @var array<string, mixed> */
            public array $writes = [];

            public function put($key, $value, $ttl = null)
            {
                $this->writes[$key] = $ttl;

                return parent::put($key, $value, $ttl);
            }
        };
        $this->app->instance(CacheFactory::class, new class($repository) implements CacheFactory {
            public function __construct(private CacheRepository $repository) {}

            public function store($name = null)
            {
                return $this->repository;
            }
        });

        Http::fake(function ($request) use ($surface) {
            if ($surface === 'public_transport_error' && str_contains($request->url(), '/sitemap.xml')) {
                throw new \Illuminate\Http\Client\ConnectionException('Isolated transport failure');
            }

            return Http::response(['status' => 'not_found'], 404);
        });

        $client = $this->app->make(AeoClient::class);
        match ($surface) {
            'aeo' => $client->forPath('/probe'),
            'markdown' => $client->getMarkdown('/probe'),
            default => $client->fetchPublicFile('sitemap'),
        };

        $prefix = match ($surface) {
            'aeo' => 'smking:aeo:',
            'markdown' => 'smking:md:',
            default => 'smking:takeover:',
        };
        $writes = array_filter($repository->writes, fn ($key) => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $writes, 'The tested public method must write its content cache.');
        $this->assertSame($variant === 'capped' ? 15 : $expected, array_values($writes)[0]);

        // Obtain diagnostics through the real legacy POST contract, not reflection.
        $client->forPath('/report-probe');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && ($request['sdk_meta']['not_found_ttl_seconds'] ?? null) === $expected);
    }
}
