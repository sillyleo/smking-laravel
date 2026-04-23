<?php

declare(strict_types=1);

namespace Smking\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Smking\Laravel\AeoClient;

/**
 * @method static \Smking\Laravel\Data\AeoResponse forPath(string $path, ?string $url = null)
 * @method static \Smking\Laravel\Data\AeoResponse forProductId(int $productId)
 *
 * @see \Smking\Laravel\AeoClient
 */
class Smking extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AeoClient::class;
    }
}
