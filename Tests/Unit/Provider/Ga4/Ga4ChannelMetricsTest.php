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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Provider\Ga4;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\UrlMetric;
use KonradMichalik\Typo3PageMetrics\Provider\Ga4\Ga4ChannelMetrics;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;

/**
 * Ga4ChannelMetricsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Ga4ChannelMetricsTest extends TestCase
{
    private Ga4ChannelMetrics $subject;

    protected function setUp(): void
    {
        $this->subject = new Ga4ChannelMetrics();
    }

    public function testTheReportSplitsThePageSessionsItAlreadyCountsByChannel(): void
    {
        $body = $this->subject->reportBody(['startDate' => '2026-08-01', 'endDate' => '2026-08-07']);

        self::assertSame([['startDate' => '2026-08-01', 'endDate' => '2026-08-07']], $body['dateRanges']);
        // pagePath rather than landingPage: the split hangs under the
        // sessions tile, which is counted against pagePath, and a
        // landingPage-scoped split would not add up to it.
        self::assertSame(
            [['name' => 'date'], ['name' => 'pagePath'], ['name' => 'sessionDefaultChannelGroup']],
            $body['dimensions'],
        );
        self::assertSame([['name' => 'sessions']], $body['metrics']);
    }

    public function testChannelGroupsAreFoldedIntoTheSharedVocabulary(): void
    {
        $metrics = $this->metricsFor([
            $this->row('Direct', '4'),
            $this->row('Organic Search', '9'),
            $this->row('Organic Social', '2'),
            $this->row('Referral', '6'),
            $this->row('Organic Video', '1'),
            $this->row('Paid Search', '3'),
            $this->row('Email', '5'),
            $this->row('Unassigned', '7'),
        ]);

        self::assertSame(
            [
                'channel:direct',
                'channel:search',
                'channel:social',
                'channel:referral',
                'channel:referral',
                'channel:campaign',
                'channel:campaign',
                'channel:other',
            ],
            array_map(static fn (UrlMetric $metric): ?string => $metric->dimension, $metrics),
        );

        foreach ($metrics as $metric) {
            self::assertSame('ga4.sessions', $metric->metric);
            self::assertSame('https://example.com/about', $metric->url);
            self::assertSame('2026-08-01', $metric->date->format('Y-m-d'));
        }
    }

    public function testAChannelGroupGoogleAddsLaterIsCountedRatherThanDropped(): void
    {
        $metrics = $this->metricsFor([$this->row('Some Future Channel', '8')]);

        self::assertCount(1, $metrics);
        self::assertSame('channel:other', $metrics[0]->dimension);
        self::assertSame(8, $metrics[0]->value);
    }

    public function testMalformedRowsAndZeroSessionsAreDropped(): void
    {
        $metrics = $this->metricsFor([
            ['dimensionValues' => 'not-an-array'],
            ['dimensionValues' => [['value' => '20260801'], ['value' => '/about']], 'metricValues' => [['value' => '4']]],
            ['dimensionValues' => [['value' => 'not-a-date'], ['value' => '/about'], ['value' => 'Direct']], 'metricValues' => [['value' => '4']]],
            ['dimensionValues' => [['value' => '20260801'], ['value' => 'about'], ['value' => 'Direct']], 'metricValues' => [['value' => '4']]],
            $this->row('Direct', '0'),
            $this->row('Direct', 'many'),
            $this->row('Direct', '4'),
        ]);

        self::assertCount(1, $metrics);
        self::assertSame(4, $metrics[0]->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $channelGroup, string $sessions): array
    {
        return [
            'dimensionValues' => [['value' => '20260801'], ['value' => '/about'], ['value' => $channelGroup]],
            'metricValues' => [['value' => $sessions]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<UrlMetric>
     */
    private function metricsFor(array $rows): array
    {
        return iterator_to_array($this->subject->metricsFor('https://example.com', $rows), false);
    }
}
