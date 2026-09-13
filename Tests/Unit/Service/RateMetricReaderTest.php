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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, RateMetricDefinition};
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use KonradMichalik\Typo3PageMetrics\Service\{RateMetricReader, RateSeriesCalculator};
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * RateMetricReaderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RateMetricReaderTest extends TestCase
{
    private DailyMetricsRepository&MockObject $repository;
    private RateMetricReader $subject;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(DailyMetricsRepository::class);
        $this->subject = new RateMetricReader($this->repository, new RateSeriesCalculator());
    }

    public function testSiteTotalsCarryBothSumsBesideTheRatio(): void
    {
        $this->repository->method('siteSeries')->willReturnCallback(
            static fn (array $pageUids, int $languageId, string $metric): array => 'bounces' === $metric
                ? ['2026-08-01' => 1, '2026-08-02' => 2]
                : ['2026-08-01' => 4, '2026-08-02' => 4],
        );

        $totals = $this->subject->forSite([1, 2], 0, $this->rate(), $this->range());

        self::assertSame(3, $totals->numerator);
        self::assertSame(8, $totals->denominator);
        self::assertSame(38, $totals->ratio);
    }

    public function testTheSiteRatioDividesTheSummedPartsRatherThanAveragingDailyRatios(): void
    {
        // One busy day at nothing and one quiet day at everything: averaged
        // per day this reads 50%, summed it reads 1%, and the summed answer
        // is the only honest one.
        $this->repository->method('siteSeries')->willReturnCallback(
            static fn (array $pageUids, int $languageId, string $metric): array => 'bounces' === $metric
                ? ['2026-08-01' => 1, '2026-08-02' => 0]
                : ['2026-08-01' => 1, '2026-08-02' => 99],
        );

        self::assertSame(1, $this->subject->forSite([1], 0, $this->rate(), $this->range())->ratio);
    }

    public function testASiteDenominatorOfZeroStaysVisibleBesideTheZeroRatio(): void
    {
        // The whole point of answering with the pair: a caller has to be able
        // to tell "never recorded" from "genuinely zero percent", which the
        // ratio alone cannot say.
        $this->repository->method('siteSeries')->willReturn(['2026-08-01' => 0]);

        $totals = $this->subject->forSite([1], 0, $this->rate(), $this->range());

        self::assertSame(0, $totals->denominator);
        self::assertSame(0, $totals->ratio);
    }

    public function testOneSiteRateCostsOneQueryPerSide(): void
    {
        $this->repository->expects(self::exactly(2))->method('siteSeries')->willReturn([]);

        $this->subject->forSite([1], 0, $this->rate(), $this->range());
    }

    public function testPerPageTotalsAreKeyedOnTheDenominatorsPages(): void
    {
        // A page with a numerator but no denominator is a ratio nothing was
        // divided by, so it is absent rather than zero.
        $this->repository->method('sumsByPage')->willReturnCallback(
            static fn (array $pageUids, int $languageId, string $metric): array => 'bounces' === $metric
                ? [1 => 3, 3 => 9]
                : [1 => 4, 2 => 8],
        );

        $totals = $this->subject->byPage([1, 2, 3], 0, $this->rate(), $this->range());

        self::assertSame([1, 2], array_keys($totals));
    }

    public function testAPageWithoutANumeratorRowCountsAsZeroAgainstItsDenominator(): void
    {
        $this->repository->method('sumsByPage')->willReturnCallback(
            static fn (array $pageUids, int $languageId, string $metric): array => 'bounces' === $metric
                ? []
                : [2 => 8],
        );

        $totals = $this->subject->byPage([2], 0, $this->rate(), $this->range());

        self::assertSame(0, $totals[2]->numerator);
        self::assertSame(8, $totals[2]->denominator);
        self::assertSame(0, $totals[2]->ratio);
    }

    public function testEachPageIsDividedOnItsOwnParts(): void
    {
        $this->repository->method('sumsByPage')->willReturnCallback(
            static fn (array $pageUids, int $languageId, string $metric): array => 'bounces' === $metric
                ? [1 => 1, 2 => 3]
                : [1 => 4, 2 => 4],
        );

        $totals = $this->subject->byPage([1, 2], 0, $this->rate(), $this->range());

        self::assertSame(25, $totals[1]->ratio);
        self::assertSame(75, $totals[2]->ratio);
    }

    public function testOnePerPageRateCostsOneQueryPerSide(): void
    {
        $this->repository->expects(self::exactly(2))->method('sumsByPage')->willReturn([]);

        $this->subject->byPage([1], 0, $this->rate(), $this->range());
    }

    private function rate(): RateMetricDefinition
    {
        return new RateMetricDefinition(
            'test.bounce_rate',
            'Bounce rate',
            '',
            'actions-x',
            MetricUnit::Percent,
            10,
            '#000000',
            'bounces',
            'visits',
        );
    }

    private function range(): DateRange
    {
        return new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-02'));
    }
}
