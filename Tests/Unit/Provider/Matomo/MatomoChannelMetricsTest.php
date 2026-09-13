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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Provider\Matomo;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\UrlMetric;
use KonradMichalik\Typo3PageMetrics\Provider\Matomo\{MatomoChannelMetrics, MatomoConfiguration};
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;

/**
 * MatomoChannelMetricsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MatomoChannelMetricsTest extends TestCase
{
    private MatomoChannelMetrics $subject;
    private DateTimeImmutable $day;

    protected function setUp(): void
    {
        $this->subject = new MatomoChannelMetrics();
        $this->day = new DateTimeImmutable('2026-08-01');
    }

    public function testBulkRequestAsksForEveryChannelInOneCall(): void
    {
        $parameters = $this->subject->bulkRequestParameters(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'), $this->day);

        self::assertSame('API.getBulkRequest', $parameters['method']);

        $subRequests = array_filter($parameters, static fn (string $key): bool => str_starts_with($key, 'urls['), \ARRAY_FILTER_USE_KEY);
        self::assertCount(5, $subRequests);

        foreach ($subRequests as $subRequest) {
            self::assertIsString($subRequest);
            self::assertStringContainsString('method=Actions.getPageUrls', $subRequest);
            self::assertStringContainsString('date=2026-08-01', $subRequest);
            self::assertStringContainsString('period=day', $subRequest);
            self::assertStringContainsString('flat=1', $subRequest);
            // Matomo's default top-100 would silently drop every page past
            // the hundredth, exactly as it would on the unsegmented report.
            self::assertStringContainsString('filter_limit=-1', $subRequest);
            self::assertStringContainsString('segment=referrerType', $subRequest);
        }
    }

    public function testEachChannelIsStoredAsADimensionOnTheVisitsMetric(): void
    {
        $metrics = $this->metricsFor([
            [['url' => 'https://example.com/foo', 'nb_visits' => 4]],
            [['url' => 'https://example.com/foo', 'nb_visits' => 9]],
            [['url' => 'https://example.com/foo', 'nb_visits' => 2]],
            [['url' => 'https://example.com/foo', 'nb_visits' => 6]],
            [['url' => 'https://example.com/foo', 'nb_visits' => 1]],
        ]);

        self::assertSame(
            ['channel:direct', 'channel:search', 'channel:social', 'channel:referral', 'channel:campaign'],
            array_map(static fn (UrlMetric $metric): ?string => $metric->dimension, $metrics),
        );

        foreach ($metrics as $metric) {
            // The split hangs under the visits tile, so it has to be the
            // same metric the tile shows or it would break down a number
            // nobody is looking at.
            self::assertSame('matomo.visits', $metric->metric);
            self::assertSame('https://example.com/foo', $metric->url);
            self::assertSame('2026-08-01', $metric->date->format('Y-m-d'));
        }

        self::assertSame([4, 9, 2, 6, 1], array_map(static fn (UrlMetric $metric): int => $metric->value, $metrics));
    }

    public function testAFailedSubRequestYieldsNothingInsteadOfFailingTheWholeImport(): void
    {
        // What Matomo puts in a slot whose sub-request could not be served,
        // e.g. because the Referrers plugin is deactivated.
        $metrics = $this->metricsFor([
            ['result' => 'error', 'message' => 'Referrers plugin is not activated'],
            [['url' => 'https://example.com/foo', 'nb_visits' => 9]],
        ]);

        self::assertCount(1, $metrics);
        self::assertSame('channel:search', $metrics[0]->dimension);
    }

    public function testAnEmptyResponseYieldsNothing(): void
    {
        self::assertSame([], $this->metricsFor([]));
    }

    public function testRowsWithoutAUsableUrlOrWithoutVisitsAreDropped(): void
    {
        $metrics = $this->metricsFor([
            [
                'not-a-row',
                ['nb_visits' => 5],
                ['url' => '', 'nb_visits' => 5],
                ['url' => 12345, 'nb_visits' => 5],
                // Zero says what the absence of the row already says, and
                // writing it would multiply the fact table by the number of
                // channels for every page that had none of them.
                ['url' => 'https://example.com/zero', 'nb_visits' => 0],
                ['url' => 'https://example.com/foo', 'nb_visits' => 3],
            ],
        ]);

        self::assertCount(1, $metrics);
        self::assertSame('https://example.com/foo', $metrics[0]->url);
    }

    /**
     * @param array<mixed> $response
     *
     * @return list<UrlMetric>
     */
    private function metricsFor(array $response): array
    {
        return iterator_to_array($this->subject->metricsFor($response, $this->day), false);
    }
}
