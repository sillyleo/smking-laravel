<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests\Support;

use PHPUnit\Framework\TestCase;
use Smking\Laravel\Support\ConfigMerge;

/**
 * Deep config merge — regression guard for the `cms.base_path` bug: a
 * customer's older published `config/smking.php` whose `cms` section
 * predates `base_path` must NOT wipe the package default (which is how
 * heartbeat reported `cms_base_path: null` and nav cards 404'd). Pure
 * function, no testbench needed.
 */
class ConfigMergeTest extends TestCase
{
    public function test_partial_customer_section_keeps_package_default_leaf(): void
    {
        // THE BUG: customer published `cms` before `base_path` existed, so
        // their config only carries `inline_styles`. A top-level array_merge
        // drops base_path; deep() keeps it.
        $merged = ConfigMerge::deep(
            ['cms' => ['inline_styles' => true, 'base_path' => '/blog']],
            ['cms' => ['inline_styles' => false]],
        );

        $this->assertSame(
            '/blog',
            $merged['cms']['base_path'],
            'package default base_path must survive a partial customer cms section',
        );
        $this->assertFalse(
            $merged['cms']['inline_styles'],
            'customer-set leaf still wins',
        );
    }

    public function test_customer_explicit_leaf_overrides_default(): void
    {
        $merged = ConfigMerge::deep(
            ['cms' => ['base_path' => '/blog']],
            ['cms' => ['base_path' => '/news']],
        );

        $this->assertSame('/news', $merged['cms']['base_path']);
    }

    public function test_list_arrays_are_replaced_not_concatenated(): void
    {
        // Crawler pattern lists / except-patterns are ordered lists —
        // concatenating would duplicate entries. Customer list wins whole.
        $merged = ConfigMerge::deep(
            ['except' => ['api/*', 'admin*']],
            ['except' => ['custom/*']],
        );

        $this->assertSame(['custom/*'], $merged['except']);
    }

    public function test_keys_only_in_default_are_preserved(): void
    {
        $merged = ConfigMerge::deep(['a' => 1, 'b' => 2], ['b' => 20]);

        $this->assertSame(1, $merged['a']);
        $this->assertSame(20, $merged['b']);
    }

    public function test_keys_only_in_custom_are_added(): void
    {
        $merged = ConfigMerge::deep(['a' => 1], ['b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $merged);
    }

    public function test_deeply_nested_associative_recurses(): void
    {
        $merged = ConfigMerge::deep(
            ['cache' => ['ttl' => ['cms' => 300, 'aeo' => 600]]],
            ['cache' => ['ttl' => ['cms' => 60]]],
        );

        $this->assertSame(60, $merged['cache']['ttl']['cms'], 'customer override applies');
        $this->assertSame(600, $merged['cache']['ttl']['aeo'], 'untouched sibling default survives');
    }

    public function test_type_mismatch_customer_wins(): void
    {
        // default leaf is a list, customer overrides with a scalar.
        $merged = ConfigMerge::deep(['x' => ['a', 'b']], ['x' => 'scalar']);

        $this->assertSame('scalar', $merged['x']);
    }
}
