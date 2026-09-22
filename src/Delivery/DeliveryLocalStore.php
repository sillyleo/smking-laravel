<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use RuntimeException;

/**
 * Private, versioned local records independent of Laravel cache eviction.
 * Readers see a complete old or new record. Mutators hold locked() across
 * read/check/write; content locks never span HTTP and never wait. A separate
 * background runner lock may cover bounded HTTP, without blocking content reads.
 * Local filesystems only.
 */
final class DeliveryLocalStore
{
    private const MAX_BYTES = 16_777_216;

    public function __construct(private readonly string $directory)
    {
    }

    public function read(string $key): ?array
    {
        $path = $this->path($key);
        $this->checkDirectory(false);
        if (is_link($path)) {
            throw new RuntimeException('delivery_local_symlink');
        }
        if (! file_exists($path)) {
            return null;
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('delivery_local_read_failed');
        }
        try {
            $json = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($json) || strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('delivery_local_size_invalid');
        }
        $record = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($record) || count($record) !== 4
            || ($record['format'] ?? null) !== 1
            || ($record['key'] ?? null) !== hash('sha256', $key)
            || ! is_array($record['data'] ?? null)
            || ! is_string($record['checksum'] ?? null)
            || ! hash_equals(hash('sha256', $this->encode($record['data'])), $record['checksum'])
        ) {
            throw new RuntimeException('delivery_local_record_invalid');
        }

        return $record['data'];
    }

    /** Atomic replacement; errors propagate and the previous file stays intact. */
    public function write(string $key, array $data): bool
    {
        $json = $this->encode([
            'format' => 1,
            'key' => hash('sha256', $key),
            'data' => $data,
            'checksum' => hash('sha256', $this->encode($data)),
        ]);
        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('delivery_local_size_invalid');
        }
        $this->checkDirectory(true);
        $path = $this->path($key);
        if (is_link($path)) {
            throw new RuntimeException('delivery_local_symlink');
        }
        $temporary = $path.'.'.bin2hex(random_bytes(12)).'.tmp';
        $stream = @fopen($temporary, 'xb');
        if ($stream === false) {
            throw new RuntimeException('delivery_local_write_failed');
        }
        try {
            if (! @chmod($temporary, 0600)) {
                throw new RuntimeException('delivery_local_permissions_failed');
            }
            $length = strlen($json);
            for ($offset = 0; $offset < $length; $offset += $written) {
                $written = @fwrite($stream, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('delivery_local_write_failed');
                }
            }
            if (! @fflush($stream) || ! @fsync($stream)) {
                throw new RuntimeException('delivery_local_sync_failed');
            }
            fclose($stream);
            $stream = null;
            if (! @rename($temporary, $path)) {
                throw new RuntimeException('delivery_local_replace_failed');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return true;
    }

    /** Null means busy. Errors are distinct from contention; lock files persist. */
    public function locked(string $key, callable $operation): mixed
    {
        $this->checkDirectory(true);
        $path = $this->path($key).'.lock';
        if (is_link($path)) {
            throw new RuntimeException('delivery_local_symlink');
        }
        $stream = @fopen($path, 'c+b');
        if ($stream === false) {
            throw new RuntimeException('delivery_local_lock_failed');
        }
        try {
            if (! @chmod($path, 0600)) {
                throw new RuntimeException('delivery_local_permissions_failed');
            }
            if (! flock($stream, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if ($wouldBlock === 1) {
                    return null;
                }
                throw new RuntimeException('delivery_local_lock_failed');
            }

            return $operation();
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/').'/'.hash('sha256', $key).'.json';
    }

    private function checkDirectory(bool $create): void
    {
        if (! str_starts_with($this->directory, '/') || str_contains($this->directory, "\0")
            || rtrim($this->directory, '/') === ''
        ) {
            throw new RuntimeException('delivery_local_path_invalid');
        }
        if ($create && ! is_dir($this->directory)
            && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)
        ) {
            throw new RuntimeException('delivery_local_directory_failed');
        }
        if (file_exists($this->directory) && (! is_dir($this->directory) || ! is_readable($this->directory))) {
            throw new RuntimeException('delivery_local_directory_failed');
        }
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
