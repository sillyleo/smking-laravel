<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests\Support;

use PHPUnit\Framework\TestCase;
use Smking\Laravel\Support\RobotsTxtBuilder;

class RobotsTxtBuilderTest extends TestCase
{
    public function test_creates_block_from_empty_input(): void
    {
        $merged = RobotsTxtBuilder::merge('', ['GPTBot' => 'allow'], 'search=yes, ai-train=no');

        $this->assertStringContainsString(RobotsTxtBuilder::BLOCK_START, $merged);
        $this->assertStringContainsString(RobotsTxtBuilder::BLOCK_END, $merged);
        $this->assertStringContainsString('User-agent: GPTBot', $merged);
        $this->assertStringContainsString('Allow: /', $merged);
        $this->assertStringContainsString('Content-Signal: search=yes, ai-train=no', $merged);
    }

    public function test_appends_block_to_existing_robots_without_duplicating_customer_rules(): void
    {
        $existing = "User-agent: *\nDisallow: /admin/\nDisallow: /cart/\n";

        $merged = RobotsTxtBuilder::merge($existing, ['ClaudeBot' => 'allow'], null);

        $this->assertStringStartsWith($existing, $merged);
        $this->assertStringContainsString('User-agent: ClaudeBot', $merged);
        // Customer's original Disallow lines must each survive exactly once.
        $this->assertSame(1, substr_count($merged, 'Disallow: /admin/'));
        $this->assertSame(1, substr_count($merged, 'Disallow: /cart/'));
    }

    public function test_replaces_existing_managed_block_in_place(): void
    {
        $original = RobotsTxtBuilder::merge(
            "User-agent: *\nDisallow: /admin/\n",
            ['GPTBot' => 'allow'],
            'search=yes',
        );

        $this->assertStringContainsString('User-agent: GPTBot', $original);

        $updated = RobotsTxtBuilder::merge(
            $original,
            ['ClaudeBot' => 'disallow'],
            'search=no',
        );

        // The customer's own Disallow line must still be there exactly once.
        $this->assertSame(1, substr_count($updated, 'Disallow: /admin/'));
        // The managed block must reflect the new policy and drop the old one.
        $this->assertStringNotContainsString('User-agent: GPTBot', $updated);
        $this->assertStringContainsString('User-agent: ClaudeBot', $updated);
        $this->assertStringContainsString('Disallow: /', $updated);
        $this->assertStringContainsString('Content-Signal: search=no', $updated);
        $this->assertStringNotContainsString('Content-Signal: search=yes', $updated);
        // Exactly one managed block — re-runs must not stack.
        $this->assertSame(1, substr_count($updated, RobotsTxtBuilder::BLOCK_START));
        $this->assertSame(1, substr_count($updated, RobotsTxtBuilder::BLOCK_END));
    }

    public function test_disallow_rule_emits_correct_directive(): void
    {
        $merged = RobotsTxtBuilder::merge('', ['CCBot' => 'disallow'], null);

        $this->assertStringContainsString("User-agent: CCBot\nDisallow: /", $merged);
    }

    public function test_skips_content_signal_block_when_signal_is_null_or_empty(): void
    {
        $merged = RobotsTxtBuilder::merge('', ['GPTBot' => 'allow'], null);
        $this->assertStringNotContainsString('Content-Signal:', $merged);

        $mergedEmpty = RobotsTxtBuilder::merge('', ['GPTBot' => 'allow'], '');
        $this->assertStringNotContainsString('Content-Signal:', $mergedEmpty);
    }

    public function test_emits_only_content_signal_when_bots_array_is_empty(): void
    {
        $merged = RobotsTxtBuilder::merge('', [], 'search=yes');

        $this->assertStringContainsString('User-agent: *', $merged);
        $this->assertStringContainsString('Content-Signal: search=yes', $merged);
    }

    public function test_has_managed_block_detects_marker(): void
    {
        $this->assertFalse(RobotsTxtBuilder::hasManagedBlock("User-agent: *\nDisallow: /admin/"));
        $this->assertTrue(RobotsTxtBuilder::hasManagedBlock(
            RobotsTxtBuilder::BLOCK_START."\nUser-agent: GPTBot\nAllow: /\n".RobotsTxtBuilder::BLOCK_END
        ));
    }

    public function test_idempotent_re_merge_produces_identical_output(): void
    {
        $first = RobotsTxtBuilder::merge('', ['GPTBot' => 'allow'], 'search=yes');
        $second = RobotsTxtBuilder::merge($first, ['GPTBot' => 'allow'], 'search=yes');

        $this->assertSame($first, $second);
    }
}
