<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests\Console;

use Illuminate\Filesystem\Filesystem;
use Smking\Laravel\Support\RobotsTxtBuilder;
use Smking\Laravel\Tests\TestCase;

class PublishRobotsTxtCommandTest extends TestCase
{
    private string $publicPath;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Sandbox public/ inside the testbench so we never touch a real
        // robots.txt. Each test cleans its own directory in tearDown.
        $this->publicPath = sys_get_temp_dir().'/smking-robots-test-'.bin2hex(random_bytes(4));
        @mkdir($this->publicPath, 0o755, true);
        $app->usePublicPath($this->publicPath);

        $app['config']->set('smking.robots.bots', ['GPTBot' => 'allow', 'ClaudeBot' => 'allow']);
        $app['config']->set('smking.robots.content_signal', 'search=yes, ai-train=no');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->publicPath);
        parent::tearDown();
    }

    public function test_creates_robots_txt_when_missing(): void
    {
        $path = $this->publicPath.'/robots.txt';
        $this->assertFileDoesNotExist($path);

        $this->artisan('smking:publish-robots')->assertExitCode(0);

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('User-agent: GPTBot', $content);
        $this->assertStringContainsString('User-agent: ClaudeBot', $content);
        $this->assertStringContainsString('Content-Signal: search=yes, ai-train=no', $content);
    }

    public function test_preserves_existing_customer_rules(): void
    {
        $path = $this->publicPath.'/robots.txt';
        $original = "User-agent: *\nDisallow: /admin/\nDisallow: /private/\n";
        file_put_contents($path, $original);

        $this->artisan('smking:publish-robots')->assertExitCode(0);

        $content = file_get_contents($path);
        $this->assertStringContainsString('Disallow: /admin/', $content);
        $this->assertStringContainsString('Disallow: /private/', $content);
        $this->assertStringContainsString('User-agent: GPTBot', $content);
    }

    public function test_replaces_managed_block_on_re_run_without_stacking(): void
    {
        $path = $this->publicPath.'/robots.txt';
        $this->artisan('smking:publish-robots')->assertExitCode(0);
        $first = file_get_contents($path);

        // Switch policy: now disallow CCBot, drop content signal.
        $this->app['config']->set('smking.robots.bots', ['CCBot' => 'disallow']);
        $this->app['config']->set('smking.robots.content_signal', null);

        $this->artisan('smking:publish-robots', ['--force' => true])->assertExitCode(0);
        $second = file_get_contents($path);

        $this->assertStringNotContainsString('User-agent: GPTBot', $second);
        $this->assertStringNotContainsString('User-agent: ClaudeBot', $second);
        $this->assertStringContainsString('User-agent: CCBot', $second);
        $this->assertStringNotContainsString('Content-Signal:', $second);
        // Exactly one managed block — proves replacement, not append.
        $this->assertSame(1, substr_count($second, RobotsTxtBuilder::BLOCK_START));
        $this->assertNotSame($first, $second);
    }

    public function test_no_op_when_already_up_to_date(): void
    {
        $path = $this->publicPath.'/robots.txt';
        $this->artisan('smking:publish-robots')->assertExitCode(0);
        $first = filemtime($path);

        // Wait so a rewrite would change mtime, then re-run without --force.
        sleep(1);
        $this->artisan('smking:publish-robots')->assertExitCode(0);

        clearstatcache();
        $this->assertSame($first, filemtime($path), 'unchanged file must not be rewritten without --force');
    }

    public function test_invalid_when_both_bots_and_signal_are_empty(): void
    {
        $this->app['config']->set('smking.robots.bots', []);
        $this->app['config']->set('smking.robots.content_signal', null);

        $this->artisan('smking:publish-robots')->assertExitCode(2); // Command::INVALID
    }
}
