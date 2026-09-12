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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\ValueObject;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DataFreshness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DataFreshnessTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DataFreshnessTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function lastImportedDayProvider(): iterable
    {
        yield 'today is current' => ['2026-09-12', false];
        yield 'yesterday is current, the day may simply not have run yet' => ['2026-09-11', false];
        yield 'two days back is the last day still explainable by scheduling' => ['2026-09-10', false];
        yield 'three days back means no run has happened' => ['2026-09-09', true];
        yield 'a week back is plainly stale' => ['2026-09-05', true];
    }

    #[DataProvider('lastImportedDayProvider')]
    public function testMarksTheDataStaleOnlyOnceSchedulingNoLongerExplainsTheGap(string $date, bool $expectedStale): void
    {
        $freshness = new DataFreshness(new DateTimeImmutable($date), new DateTimeImmutable('2026-09-12'));

        self::assertSame($expectedStale, $freshness->isStale);
    }

    public function testKeepsTheDateItWasGiven(): void
    {
        $date = new DateTimeImmutable('2026-09-11');

        self::assertSame($date, (new DataFreshness($date, new DateTimeImmutable('2026-09-12')))->date);
    }
}
