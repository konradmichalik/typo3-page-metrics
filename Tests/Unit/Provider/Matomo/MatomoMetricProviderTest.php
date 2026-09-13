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
use KonradMichalik\Typo3PageMetrics\Configuration\{GoalConfigurationParser, SiteConfigurationReader};
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, MetricUnit};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Provider\Matomo\{MatomoApiClient, MatomoChannelMetrics, MatomoConfiguration, MatomoConfigurationResolver, MatomoGoalMetrics, MatomoMetricProvider};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * MatomoMetricProviderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MatomoMetricProviderTest extends TestCase
{
    /** @var MatomoConfigurationResolver&\PHPUnit\Framework\MockObject\MockObject */
    private MatomoConfigurationResolver $resolver;

    /** @var MatomoApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private MatomoApiClient $apiClient;
    private MatomoMetricProvider $subject;
    private Site $site;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(MatomoConfigurationResolver::class);
        $this->apiClient = $this->createMock(MatomoApiClient::class);
        $this->subject = new MatomoMetricProvider($this->resolver, $this->apiClient, new MatomoGoalMetrics(new SiteConfigurationReader(new GoalConfigurationParser())), new MatomoChannelMetrics());
        $this->site = new Site('main', 1, ['base' => 'https://example.com/']);
    }

    public function testGetIdentifierReturnsMatomo(): void
    {
        self::assertSame('matomo', $this->subject->getIdentifier());
    }

    public function testGetMetricDefinitionsReturnsPageviewsAndVisits(): void
    {
        $identifiers = array_map(
            static fn ($definition) => $definition->identifier,
            $this->subject->getMetricDefinitions(),
        );

        self::assertSame(['matomo.pageviews', 'matomo.visits'], $identifiers);
    }

    public function testGetRateMetricDefinitionsReturnsAvgTimeOnPageAndBounceRate(): void
    {
        $identifiers = array_map(
            static fn ($definition) => $definition->identifier,
            $this->subject->getRateMetricDefinitions(),
        );

        self::assertSame(['matomo.avg_time_on_page', 'matomo.bounce_rate'], $identifiers);
    }

    public function testGetSiteMetricDefinitionsIsEmptyForASiteWithoutGoals(): void
    {
        self::assertSame([], $this->subject->getSiteMetricDefinitions($this->site));
    }

    public function testGetSiteMetricDefinitionsDeclaresOneTilePerGoalPlusItsRevenue(): void
    {
        $definitions = $this->subject->getSiteMetricDefinitions($this->siteWithGoals());

        $identifiers = array_map(static fn ($definition) => $definition->identifier, $definitions);
        self::assertSame(['matomo.goal.3.conversions', 'matomo.goal.3.revenue', 'matomo.goal.ecommerceOrder.conversions'], $identifiers);

        self::assertSame('Newsletter signup', $definitions[0]->label);
        self::assertSame(MetricUnit::Count, $definitions[0]->unit);
        self::assertSame(MetricPolarity::HigherIsBetter, $definitions[0]->polarity);

        self::assertSame('Newsletter signup revenue', $definitions[1]->label);
        self::assertSame(MetricUnit::Currency, $definitions[1]->unit);

        // Behind the fixed metrics, which end at 40.
        self::assertGreaterThan(40, $definitions[0]->sortOrder);
        self::assertSame(MetricPolarity::Neutral, $definitions[2]->polarity);
    }

    public function testSupportsReturnsTrueWhenConfigurationResolves(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));

        self::assertTrue($this->subject->supports($this->site));
    }

    public function testSupportsReturnsFalseWhenNotConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(null);

        self::assertFalse($this->subject->supports($this->site));
    }

    public function testGetExternalLinkBuildsAMatomoDeepLinkFilteredByPageUrlAndRange(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));
        $link = $this->subject->getExternalLink($this->site, 'https://example.com/about', $range);

        self::assertNotNull($link);
        self::assertStringStartsWith('https://matomo.example.com/index.php?', $link);
        self::assertStringContainsString('idSite=3', $link);
        self::assertStringContainsString('period=range', $link);
        self::assertStringContainsString('date=2026-08-01,2026-08-07', $link);
        self::assertStringContainsString(rawurlencode('pageUrl==https://example.com/about'), $link);
    }

    public function testGetExternalLinkReturnsNullWhenNotConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(null);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));

        self::assertNull($this->subject->getExternalLink($this->site, 'https://example.com/about', $range));
    }

    public function testFetchYieldsPageviewsAndVisitsPerRowPerDay(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn([
            ['label' => '/foo', 'url' => 'https://example.com/foo', 'nb_hits' => 10, 'nb_visits' => 7],
            ['label' => '/bar', 'url' => 'https://example.com/bar', 'nb_hits' => 3, 'nb_visits' => 2],
        ]);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        self::assertCount(10, $metrics);
        self::assertSame('https://example.com/foo', $metrics[0]->url);
        self::assertSame('matomo.pageviews', $metrics[0]->metric);
        self::assertSame(10, $metrics[0]->value);
        self::assertSame('matomo.visits', $metrics[1]->metric);
        self::assertSame(7, $metrics[1]->value);
        self::assertSame('https://example.com/bar', $metrics[5]->url);
    }

    public function testFetchYieldsTimeSpentEntriesAndBouncesFromTheSameRow(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn([
            [
                'label' => '/foo',
                'url' => 'https://example.com/foo',
                'nb_hits' => 10,
                'nb_visits' => 7,
                'sum_time_spent' => 420,
                'entry_nb_visits' => 5,
                'entry_bounce_count' => 2,
            ],
        ]);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        self::assertCount(5, $metrics);
        self::assertSame('matomo.time_spent_seconds', $metrics[2]->metric);
        self::assertSame(420, $metrics[2]->value);
        self::assertSame('matomo.entries', $metrics[3]->metric);
        self::assertSame(5, $metrics[3]->value);
        self::assertSame('matomo.entry_bounces', $metrics[4]->metric);
        self::assertSame(2, $metrics[4]->value);
    }

    public function testFetchYieldsOneRowGroupPerDayInTheRange(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn([
            ['label' => '/foo', 'url' => 'https://example.com/foo', 'nb_hits' => 1, 'nb_visits' => 1],
        ]);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-02'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        self::assertCount(10, $metrics);
        self::assertSame('2026-08-01', $metrics[0]->date->format('Y-m-d'));
        self::assertSame('2026-08-02', $metrics[5]->date->format('Y-m-d'));
    }

    public function testFetchYieldsNothingWhenNotConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(null);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));

        self::assertSame([], iterator_to_array($this->subject->fetch($this->site, $range)));
    }

    public function testFetchCallsApiOncePerCalendarDayWithPeriodDayAndFlat(): void
    {
        $configuration = new MatomoConfiguration('https://matomo.example.com/', 3, 'token');
        $this->resolver->method('resolve')->willReturn($configuration);

        $calls = [];
        $this->apiClient->method('request')
            ->willReturnCallback(static function (MatomoConfiguration $passedConfiguration, array $parameters) use (&$calls, $configuration) {
                self::assertSame($configuration, $passedConfiguration);
                $calls[] = $parameters;

                return [];
            });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-02'));
        iterator_to_array($this->subject->fetch($this->site, $range));

        $calls = $this->pageUrlCalls($calls);
        self::assertCount(2, $calls);
        self::assertSame('Actions.getPageUrls', $calls[0]['method']);
        self::assertSame('day', $calls[0]['period']);
        self::assertSame('2026-08-01', $calls[0]['date']);
        self::assertSame(1, $calls[0]['flat']);
        self::assertSame('day', $calls[1]['period']);
        self::assertSame('2026-08-02', $calls[1]['date']);
        self::assertNotSame('range', $calls[0]['period']);
    }

    public function testFetchRequestsAllRowsInsteadOfMatomosDefaultTop100(): void
    {
        $configuration = new MatomoConfiguration('https://matomo.example.com/', 3, 'token');
        $this->resolver->method('resolve')->willReturn($configuration);

        $calls = [];
        $this->apiClient->method('request')->willReturnCallback(static function (MatomoConfiguration $passedConfiguration, array $parameters) use (&$calls) {
            $calls[] = $parameters;

            return [];
        });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-02'));
        iterator_to_array($this->subject->fetch($this->site, $range));

        $calls = $this->pageUrlCalls($calls);
        self::assertCount(2, $calls);

        foreach ($calls as $call) {
            self::assertArrayHasKey('filter_limit', $call);
            self::assertSame(-1, $call['filter_limit']);
        }
    }

    public function testFetchAsksForTheChannelSplitOnceADayAlongsideThePageReport(): void
    {
        $configuration = new MatomoConfiguration('https://matomo.example.com/', 3, 'token');
        $this->resolver->method('resolve')->willReturn($configuration);

        $calls = [];
        $this->apiClient->method('request')->willReturnCallback(static function (MatomoConfiguration $passedConfiguration, array $parameters) use (&$calls): array {
            $calls[] = $parameters;

            return [];
        });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-02'));
        iterator_to_array($this->subject->fetch($this->site, $range));

        // Two days, two page reports and two bulk requests. The split costs
        // one request a day however many pages the site has, which is the
        // whole reason the segment runs channel-first rather than page-first.
        self::assertCount(4, $calls);

        $bulkCalls = array_values(array_filter($calls, static fn (array $call): bool => 'API.getBulkRequest' === ($call['method'] ?? null)));
        self::assertCount(2, $bulkCalls);
        self::assertStringContainsString('date=2026-08-01', (string) $bulkCalls[0]['urls[0]']);
        self::assertStringContainsString('date=2026-08-02', (string) $bulkCalls[1]['urls[0]']);
    }

    public function testFetchYieldsChannelDimensionsOnTheVisitsMetric(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturnCallback(static function (MatomoConfiguration $configuration, array $parameters): array {
            if ('API.getBulkRequest' === ($parameters['method'] ?? null)) {
                return [
                    [['url' => 'https://example.com/foo', 'nb_visits' => 5]],
                    [['url' => 'https://example.com/foo', 'nb_visits' => 2]],
                ];
            }

            return [['label' => '/foo', 'url' => 'https://example.com/foo', 'nb_hits' => 10, 'nb_visits' => 7]];
        });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        $headline = array_values(array_filter($metrics, static fn ($metric): bool => 'matomo.visits' === $metric->metric && null === $metric->dimension));
        $channels = array_values(array_filter($metrics, static fn ($metric): bool => null !== $metric->dimension));

        // The headline row is the one the tile shows and is not touched by
        // the split: 7 visits, of which 5 direct and 2 from search.
        self::assertCount(1, $headline);
        self::assertSame(7, $headline[0]->value);
        self::assertSame([5, 2], array_map(static fn ($metric): int => $metric->value, $channels));
        self::assertSame(['channel:direct', 'channel:search'], array_map(static fn ($metric): ?string => $metric->dimension, $channels));
    }

    public function testFetchAsksForGoalColumnsOnlyWhereGoalsAreConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));

        $calls = [];
        $this->apiClient->method('request')->willReturnCallback(static function (MatomoConfiguration $configuration, array $parameters) use (&$calls): array {
            $calls[] = $parameters;

            return [];
        });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));

        iterator_to_array($this->subject->fetch($this->site, $range));
        $pageUrlCalls = $this->pageUrlCalls($calls);
        self::assertArrayNotHasKey('idGoal', $pageUrlCalls[0]);
        self::assertArrayNotHasKey('filter_update_columns_when_show_all_goals', $pageUrlCalls[0]);

        iterator_to_array($this->subject->fetch($this->siteWithGoals(), $range));
        $pageUrlCalls = $this->pageUrlCalls($calls);
        self::assertSame(0, $pageUrlCalls[1]['idGoal']);
        self::assertSame(1, $pageUrlCalls[1]['filter_update_columns_when_show_all_goals']);
        self::assertSame('Actions.getPageUrls', $pageUrlCalls[1]['method']);
    }

    public function testFetchYieldsGoalConversionsAndRevenueInMinorUnits(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn([
            [
                'label' => '/foo',
                'url' => 'https://example.com/foo',
                'nb_hits' => 10,
                'goal_3_nb_conversions' => 2,
                'goal_3_revenue' => 12.5,
                'goal_ecommerceOrder_nb_conversions' => '1',
            ],
        ]);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($this->siteWithGoals(), $range), false);

        self::assertCount(8, $metrics);
        self::assertSame('matomo.goal.3.conversions', $metrics[5]->metric);
        self::assertSame(2, $metrics[5]->value);
        self::assertSame('matomo.goal.3.revenue', $metrics[6]->metric);
        self::assertSame(1250, $metrics[6]->value);
        self::assertSame('matomo.goal.ecommerceOrder.conversions', $metrics[7]->metric);
        self::assertSame(1, $metrics[7]->value);
    }

    public function testFetchDropsGoalColumnsThatAreAbsentZeroOrNotNumeric(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn([
            [
                'label' => '/foo',
                'url' => 'https://example.com/foo',
                'nb_hits' => 10,
                'goal_3_nb_conversions' => 0,
                'goal_3_revenue' => 'not-a-number',
                'goal_ecommerceOrder_nb_conversions' => ['unexpected'],
            ],
        ]);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($this->siteWithGoals(), $range), false);

        // Only the five fixed metrics, no goal rows at all.
        self::assertCount(5, $metrics);
    }

    public function testFetchSkipsRowsWithoutAUsableUrl(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn([
            'not-a-row',
            ['label' => '/no-url', 'nb_hits' => 5],
            ['label' => '/empty-url', 'url' => '', 'nb_hits' => 5],
            ['label' => '/numeric-url', 'url' => 12345, 'nb_hits' => 5],
            ['label' => '/foo', 'url' => 'https://example.com/foo', 'nb_hits' => 10, 'nb_visits' => 7],
        ]);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        foreach ($metrics as $metric) {
            self::assertSame('https://example.com/foo', $metric->url);
        }

        self::assertNotSame([], $metrics);
    }

    /**
     * The page reports only. Every day also carries one bulk request for the
     * channel split, which asks nothing about goals, page size or period.
     *
     * @param list<array<string, int|string>> $calls
     *
     * @return list<array<string, int|string>>
     */
    private function pageUrlCalls(array $calls): array
    {
        return array_values(array_filter($calls, static fn (array $call): bool => 'Actions.getPageUrls' === ($call['method'] ?? null)));
    }

    private function siteWithGoals(): Site
    {
        return new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'goals' => [
                    ['provider' => 'matomo', 'id' => 3, 'label' => 'Newsletter signup', 'revenue' => true],
                    ['provider' => 'matomo', 'id' => 'ecommerceOrder', 'label' => 'Orders', 'polarity' => 'neutral'],
                    // A goal of another provider never reaches Matomo's tiles.
                    ['provider' => 'ga4', 'id' => 'generate_lead', 'label' => 'Lead'],
                ],
            ],
        ]);
    }
}
