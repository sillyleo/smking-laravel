<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Smking\Laravel\Delivery\DeliveryLocalStore;
use Symfony\Component\Process\Process;

class DeliveryLocalStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/smking-store-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
    }

    public function test_reads_do_not_create_files_and_records_survive_a_new_instance(): void
    {
        $store = new DeliveryLocalStore($this->directory);
        $this->assertNull($store->read('site:content'));
        $this->assertDirectoryDoesNotExist($this->directory);
        $this->assertTrue($store->write('site:content', ['body' => '內容', 'weight' => 1.0]));
        $this->assertSame(['body' => '內容', 'weight' => 1.0], (new DeliveryLocalStore($this->directory))->read('site:content'));
        $this->assertSame(0600, fileperms($this->path('site:content')) & 0777);
        $this->assertSame(0700, fileperms($this->directory) & 0777);
    }

    public function test_incomplete_files_are_ignored_and_bad_writes_leave_previous_record(): void
    {
        $store = new DeliveryLocalStore($this->directory);
        $store->write('entry', ['body' => 'last good']);
        file_put_contents($this->path('entry').'.interrupted.tmp', '{');
        try {
            $store->write('entry', ['body' => NAN]);
            $this->fail('Invalid JSON must not replace content');
        } catch (\JsonException) {
            $this->assertSame(['body' => 'last good'], $store->read('entry'));
        }
        chmod($this->directory, 0500);
        try {
            $store->write('entry', ['body' => 'new']);
            $this->fail('Unwritable storage must not report success');
        } catch (\RuntimeException $exception) {
            $this->assertSame('delivery_local_write_failed', $exception->getMessage());
            $this->assertSame(['body' => 'last good'], $store->read('entry'));
        } finally {
            chmod($this->directory, 0700);
        }
    }

    public function test_corrupt_cross_key_and_future_format_records_fail_closed(): void
    {
        $store = new DeliveryLocalStore($this->directory);
        $store->write('first', ['body' => 'trusted']);
        $record = json_decode(file_get_contents($this->path('first')), true);
        foreach (['checksum', 'format', 'key'] as $field) {
            $invalid = $record;
            $invalid[$field] = $field === 'format' ? 2 : 'incorrect';
            file_put_contents($this->path('first'), json_encode($invalid));
            try {
                $store->read('first');
                $this->fail('Invalid envelope accepted: '.$field);
            } catch (\RuntimeException $exception) {
                $this->assertSame('delivery_local_record_invalid', $exception->getMessage());
            }
        }
        file_put_contents($this->path('second'), json_encode($record));
        $this->expectExceptionMessage('delivery_local_record_invalid');
        $store->read('second');
    }

    public function test_symlink_record_is_neither_followed_nor_overwritten(): void
    {
        $store = new DeliveryLocalStore($this->directory);
        $store->write('original', ['body' => 'protected']);
        symlink($this->path('original'), $this->path('link'));
        foreach (['read', 'write'] as $method) {
            try {
                $method === 'read' ? $store->read('link') : $store->write('link', ['body' => 'bad']);
                $this->fail('Symlink accepted');
            } catch (\RuntimeException $exception) {
                $this->assertSame('delivery_local_symlink', $exception->getMessage());
            }
        }
        $this->assertSame(['body' => 'protected'], $store->read('original'));
    }

    public function test_lock_is_nonblocking_across_processes_and_released_after_exception(): void
    {
        $store = new DeliveryLocalStore($this->directory);
        $probe = new Process([PHP_BINARY, '-r',
            'require $argv[1]; $s = new \\Smking\\Laravel\\Delivery\\DeliveryLocalStore($argv[2]); echo json_encode($s->locked("entry", fn () => true));',
            dirname(__DIR__).'/src/Delivery/DeliveryLocalStore.php', $this->directory,
        ]);
        $probe->setTimeout(5);
        try {
            $store->locked('entry', function () use ($probe): void {
                $probe->mustRun();
                $this->assertSame('null', $probe->getOutput());
                throw new \RuntimeException('interrupted');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('interrupted', $exception->getMessage());
        }
        $probe->mustRun();
        $this->assertSame('true', $probe->getOutput());
    }

    private function path(string $key): string
    {
        return $this->directory.'/'.hash('sha256', $key).'.json';
    }
}
