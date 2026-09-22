<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/** Operator-supplied finite release checklist, not a discovery or sync protocol. */
final class DeliveryPreparationPlan
{
    private function __construct(public readonly string $hash, public readonly array $targets) {}

    public static function load(string $path, ConfigRepository $config): self
    {
        // Only regular local files: no HTTP/stream wrappers, pipes, or secret URLs.
        if (! str_starts_with($path, '/') || str_contains($path, "\0") || is_link($path) || ! is_file($path)) {
            throw new RuntimeException('invalid_preparation_plan');
        }
        $json = file_get_contents($path, false, null, 0, 1_048_577);
        if (! is_string($json) || strlen($json) > 1_048_576) throw new RuntimeException('invalid_preparation_plan');
        $input = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $key = $config->get('smking.api_key');
        $source = $config->get('smking.base_url');
        if (! is_array($input) || count($input) !== 4 || ($input['format'] ?? null) !== 1
            || ! is_string($source) || ($input['source'] ?? null) !== rtrim($source, '/')
            || ! is_string($key) || $key === '' || ($input['keyFingerprint'] ?? null) !== hash('sha256', $key)
            || ! is_array($input['targets'] ?? null) || ! array_is_list($input['targets'])
            || count($input['targets']) < 1 || count($input['targets']) > 1000
        ) throw new RuntimeException('invalid_preparation_plan');

        $targets = [];
        $seen = [];
        foreach ($input['targets'] as $inputTarget) {
            $target = is_array($inputTarget) ? DeliveryTargetState::normalize($inputTarget) : null;
            if ($target === null || $target['revision'] < 1 || $target['generation'] !== $target['revision']
                || ($target['action'] === 'update' && $target['withdrawalRevision'] >= $target['revision'])
            ) throw new RuntimeException('invalid_preparation_plan');
            $identity = $target['resource'].'|'.$target['identifier'];
            if (isset($seen[$identity])) throw new RuntimeException('invalid_preparation_plan');
            $seen[$identity] = true;
            $targets[] = $target;
        }
        return new self(hash('sha256', $json), $targets);
    }
}
