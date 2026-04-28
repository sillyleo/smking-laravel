<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests\Support;

use PHPUnit\Framework\TestCase;
use Smking\Laravel\Support\PathNormalizer;

class PathNormalizerTest extends TestCase
{
    /**
     * @dataProvider canonicalCases
     */
    public function test_canonical(string $input, string $expected): void
    {
        $this->assertSame($expected, PathNormalizer::canonical($input));
    }

    public static function canonicalCases(): array
    {
        return [
            'empty' => ['', '/'],
            'root' => ['/', '/'],
            'no leading slash' => ['products', '/products'],
            'leading slash' => ['/products', '/products'],
            'trailing slash stripped' => ['/products/', '/products'],
            'both slashes stripped' => ['products/widget/', '/products/widget'],
            'multiple trailing not double-stripped' => ['/products/widget//', '/products/widget'],
            'whitespace trimmed' => ['  /products/  ', '/products'],
            'nested deep' => ['/a/b/c/d/', '/a/b/c/d'],
        ];
    }
}
