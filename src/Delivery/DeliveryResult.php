<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

final class DeliveryResult
{
    public function __construct(
        public readonly ?DeliverySnapshot $snapshot = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $error = null,
        public readonly bool $refreshRequired = false,
        public readonly ?string $denialScope = null,
    ) {
    }
}
