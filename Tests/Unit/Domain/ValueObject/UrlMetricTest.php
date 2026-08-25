<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\ValueObject;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\UrlMetric;
use PHPUnit\Framework\TestCase;


/**
 * UrlMetricTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */

final class UrlMetricTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $metric = new UrlMetric('https://example.com/foo', $date, 'matomo.pageviews', 42, 'mobile');

        self::assertSame('https://example.com/foo', $metric->url);
        self::assertSame($date, $metric->date);
        self::assertSame('matomo.pageviews', $metric->metric);
        self::assertSame(42, $metric->value);
        self::assertSame('mobile', $metric->dimension);
    }

    public function testDimensionDefaultsToNull(): void
    {
        $metric = new UrlMetric('https://example.com/foo', new DateTimeImmutable(), 'matomo.pageviews', 1);

        self::assertNull($metric->dimension);
    }
}
