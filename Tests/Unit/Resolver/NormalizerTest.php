<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Resolver;

use KonradMichalik\Typo3PageMetrics\Resolver\Normalizer;
use PHPUnit\Framework\TestCase;

/**
 * NormalizerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class NormalizerTest extends TestCase
{
    private Normalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new Normalizer();
    }

    public function testDropsQueryStringByDefault(): void
    {
        self::assertSame(
            'https://example.com/foo',
            $this->normalizer->normalize('https://example.com/foo?utm_source=x', true, []),
        );
    }

    public function testKeepsAllowlistedQueryParams(): void
    {
        self::assertSame(
            'https://example.com/search?tx_solr%5Bq%5D=typo3',
            $this->normalizer->normalize('https://example.com/search?tx_solr[q]=typo3&utm_source=x', true, ['tx_solr[q]']),
        );
    }

    public function testKeepsQueryStringWhenDropDisabled(): void
    {
        self::assertSame(
            'https://example.com/foo?a=1&b=2',
            $this->normalizer->normalize('https://example.com/foo?a=1&b=2', false, []),
        );
    }

    public function testUnifiesTrailingSlashByRemovingIt(): void
    {
        self::assertSame(
            'https://example.com/foo',
            $this->normalizer->normalize('https://example.com/foo/', true, []),
        );
    }

    public function testKeepsRootSlash(): void
    {
        self::assertSame(
            'https://example.com/',
            $this->normalizer->normalize('https://example.com/', true, []),
        );
    }

    public function testLowercasesHost(): void
    {
        self::assertSame(
            'https://example.com/Foo',
            $this->normalizer->normalize('https://EXAMPLE.com/Foo', true, []),
        );
    }

    public function testStripsDefaultHttpsPort(): void
    {
        self::assertSame(
            'https://example.com/foo',
            $this->normalizer->normalize('https://example.com:443/foo', true, []),
        );
    }

    public function testStripsDefaultHttpPort(): void
    {
        self::assertSame(
            'http://example.com/foo',
            $this->normalizer->normalize('http://example.com:80/foo', true, []),
        );
    }

    public function testKeepsNonDefaultPort(): void
    {
        self::assertSame(
            'https://example.com:8443/foo',
            $this->normalizer->normalize('https://example.com:8443/foo', true, []),
        );
    }
}
