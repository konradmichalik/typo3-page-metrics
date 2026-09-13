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

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Configuration\{GoalConfigurationParser, SiteConfigurationReader};
use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricPolarity;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Provider\Ga4\{Ga4ApiClient, Ga4ChannelMetrics, Ga4Configuration, Ga4ConfigurationResolver, Ga4KeyEventMetrics, Ga4MetricProvider};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Ga4MetricProviderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Ga4MetricProviderTest extends TestCase
{
    /** @var Ga4ConfigurationResolver&\PHPUnit\Framework\MockObject\MockObject */
    private Ga4ConfigurationResolver $resolver;

    /** @var Ga4ApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private Ga4ApiClient $apiClient;
    private Ga4MetricProvider $subject;
    private Site $site;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(Ga4ConfigurationResolver::class);
        $this->apiClient = $this->createMock(Ga4ApiClient::class);
        $this->subject = new Ga4MetricProvider($this->resolver, $this->apiClient, new Ga4KeyEventMetrics(new SiteConfigurationReader(new GoalConfigurationParser())), new Ga4ChannelMetrics());
        $this->site = new Site('main', 1, ['base' => 'https://example.com/']);
    }

    public function testGetIdentifierReturnsGa4(): void
    {
        self::assertSame('ga4', $this->subject->getIdentifier());
    }

    public function testGetMetricDefinitionsReturnsPageviewsAndSessions(): void
    {
        $identifiers = array_map(static fn ($definition) => $definition->identifier, $this->subject->getMetricDefinitions());

        self::assertSame(['ga4.pageviews', 'ga4.sessions'], $identifiers);
    }

    public function testGetRateMetricDefinitionsReturnsAvgTimeOnPageAndBounceRate(): void
    {
        $identifiers = array_map(static fn ($definition) => $definition->identifier, $this->subject->getRateMetricDefinitions());

        self::assertSame(['ga4.avg_time_on_page', 'ga4.bounce_rate'], $identifiers);
    }

    public function testGetSiteMetricDefinitionsIsEmptyForASiteWithoutGoals(): void
    {
        self::assertSame([], $this->subject->getSiteMetricDefinitions($this->site));
    }

    public function testGetSiteMetricDefinitionsDeclaresOneTilePerConfiguredKeyEvent(): void
    {
        $definitions = $this->subject->getSiteMetricDefinitions($this->siteWithGoals());

        $identifiers = array_map(static fn ($definition) => $definition->identifier, $definitions);
        self::assertSame(['ga4.key_event.generate_lead', 'ga4.key_event.purchase'], $identifiers);
        self::assertSame('Lead', $definitions[0]->label);
        self::assertSame(MetricPolarity::HigherIsBetter, $definitions[0]->polarity);
        self::assertGreaterThan(40, $definitions[0]->sortOrder);
    }

    public function testSupportsReturnsTrueWhenConfigurationResolves(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));

        self::assertTrue($this->subject->supports($this->site));
    }

    public function testSupportsReturnsFalseWhenNotConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(null);

        self::assertFalse($this->subject->supports($this->site));
    }

    public function testGetExternalLinkAlwaysReturnsNull(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));

        self::assertNull($this->subject->getExternalLink($this->site, 'https://example.com/about', new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'))));
    }

    public function testFetchYieldsNothingWhenNotConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(null);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));

        self::assertSame([], iterator_to_array($this->subject->fetch($this->site, $range)));
    }

    public function testFetchYieldsPageviewsSessionsAndEngagementTimeFromThePageReport(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));
        $this->apiClient->method('runReport')->willReturnCallback(static function (Ga4Configuration $configuration, array $body): array {
            if (['name' => 'pagePath'] === ($body['dimensions'][1] ?? null)) {
                return [
                    'rows' => [
                        [
                            'dimensionValues' => [['value' => '20260801'], ['value' => '/about']],
                            'metricValues' => [['value' => '42'], ['value' => '10'], ['value' => '123.7']],
                        ],
                    ],
                ];
            }

            return ['rows' => []];
        });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        self::assertCount(3, $metrics);
        self::assertSame('https://example.com/about', $metrics[0]->url);
        self::assertSame('ga4.pageviews', $metrics[0]->metric);
        self::assertSame(42, $metrics[0]->value);
        self::assertSame('ga4.sessions', $metrics[1]->metric);
        self::assertSame(10, $metrics[1]->value);
        self::assertSame('ga4.engagement_time_seconds', $metrics[2]->metric);
        self::assertSame(124, $metrics[2]->value);
        self::assertSame('2026-08-01', $metrics[0]->date->format('Y-m-d'));
    }

    public function testFetchSendsNoKeyEventReportForASiteWithoutGoals(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));

        $bodies = [];
        $this->apiClient->method('runReport')->willReturnCallback(static function (Ga4Configuration $configuration, array $body) use (&$bodies): array {
            $bodies[] = $body;

            return ['rows' => []];
        });

        iterator_to_array($this->subject->fetch($this->site, new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'))), false);

        // The page report, the landing report and the channel split. No
        // fourth one, because this site configured no key events.
        self::assertCount(3, $bodies);

        foreach ($bodies as $body) {
            self::assertArrayNotHasKey('dimensionFilter', $body);
        }
    }

    public function testFetchSplitsSessionsByChannelInOneExtraReportForTheWholeRange(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));

        $channelBodies = [];
        $this->apiClient->method('runReport')->willReturnCallback(static function (Ga4Configuration $configuration, array $body) use (&$channelBodies): array {
            if (['name' => 'sessionDefaultChannelGroup'] !== ($body['dimensions'][2] ?? null)) {
                return ['rows' => []];
            }

            $channelBodies[] = $body;

            return [
                'rows' => [
                    [
                        'dimensionValues' => [['value' => '20260801'], ['value' => '/about'], ['value' => 'Organic Search']],
                        'metricValues' => [['value' => '6']],
                    ],
                    [
                        'dimensionValues' => [['value' => '20260802'], ['value' => '/about'], ['value' => 'Paid Social']],
                        'metricValues' => [['value' => '2']],
                    ],
                ],
            ];
        });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-02'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        // One report covers the whole range, because date is a dimension of
        // it just as it is of the two reports already sent.
        self::assertCount(1, $channelBodies);
        self::assertSame([['startDate' => '2026-08-01', 'endDate' => '2026-08-02']], $channelBodies[0]['dateRanges']);

        self::assertCount(2, $metrics);
        self::assertSame('ga4.sessions', $metrics[0]->metric);
        self::assertSame('channel:search', $metrics[0]->dimension);
        self::assertSame(6, $metrics[0]->value);
        self::assertSame('https://example.com/about', $metrics[0]->url);
        self::assertSame('2026-08-01', $metrics[0]->date->format('Y-m-d'));
        self::assertSame('channel:campaign', $metrics[1]->dimension);
        self::assertSame('2026-08-02', $metrics[1]->date->format('Y-m-d'));
    }

    public function testFetchYieldsKeyEventsFromAReportOfTheirOwn(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));

        $keyEventBodies = [];
        $this->apiClient->method('runReport')->willReturnCallback(static function (Ga4Configuration $configuration, array $body) use (&$keyEventBodies): array {
            if (!isset($body['dimensionFilter'])) {
                return ['rows' => []];
            }

            $keyEventBodies[] = $body;

            return [
                'rows' => [
                    [
                        'dimensionValues' => [['value' => '20260801'], ['value' => '/about'], ['value' => 'generate_lead']],
                        'metricValues' => [['value' => '3']],
                    ],
                    // Zero, a malformed count and an event nobody configured
                    // are all dropped rather than stored.
                    [
                        'dimensionValues' => [['value' => '20260801'], ['value' => '/about'], ['value' => 'purchase']],
                        'metricValues' => [['value' => '0']],
                    ],
                    [
                        'dimensionValues' => [['value' => '20260801'], ['value' => '/about'], ['value' => 'purchase']],
                        'metricValues' => [['value' => 'many']],
                    ],
                    [
                        'dimensionValues' => [['value' => '20260801'], ['value' => '/about'], ['value' => 'scroll']],
                        'metricValues' => [['value' => '9']],
                    ],
                ],
            ];
        });

        $metrics = iterator_to_array($this->subject->fetch($this->siteWithGoals(), new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'))), false);

        self::assertCount(1, $metrics);
        self::assertSame('ga4.key_event.generate_lead', $metrics[0]->metric);
        self::assertSame(3, $metrics[0]->value);
        self::assertSame('https://example.com/about', $metrics[0]->url);
        self::assertSame('2026-08-01', $metrics[0]->date->format('Y-m-d'));

        self::assertCount(1, $keyEventBodies);
        self::assertSame([['name' => 'date'], ['name' => 'pagePath'], ['name' => 'eventName']], $keyEventBodies[0]['dimensions']);
        self::assertSame([['name' => 'keyEvents']], $keyEventBodies[0]['metrics']);
        self::assertSame(['generate_lead', 'purchase'], $keyEventBodies[0]['dimensionFilter']['filter']['inListFilter']['values']);
    }

    public function testFetchYieldsLandingSessionsAndDerivedBouncesFromTheLandingReport(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));
        $this->apiClient->method('runReport')->willReturnCallback(static function (Ga4Configuration $configuration, array $body): array {
            if (['name' => 'landingPage'] === ($body['dimensions'][1] ?? null)) {
                return [
                    'rows' => [
                        [
                            'dimensionValues' => [['value' => '20260801'], ['value' => '/about']],
                            'metricValues' => [['value' => '20'], ['value' => '0.5']],
                        ],
                    ],
                ];
            }

            return ['rows' => []];
        });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        self::assertCount(2, $metrics);
        self::assertSame('ga4.landing_sessions', $metrics[0]->metric);
        self::assertSame(20, $metrics[0]->value);
        self::assertSame('ga4.landing_bounces', $metrics[1]->metric);
        self::assertSame(10, $metrics[1]->value);
    }

    public function testFetchPaginatesThroughAllRowsWhenRowCountExceedsASinglePage(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));

        $offsetsSeen = [];

        $this->apiClient->method('runReport')->willReturnCallback(static function (Ga4Configuration $configuration, array $body) use (&$offsetsSeen): array {
            if ([['name' => 'date'], ['name' => 'pagePath']] !== ($body['dimensions'] ?? null)) {
                return ['rows' => []];
            }

            $offset = $body['offset'] ?? 0;
            $offsetsSeen[] = $offset;

            if (0 === $offset) {
                return [
                    'rows' => [
                        [
                            'dimensionValues' => [['value' => '20260801'], ['value' => '/one']],
                            'metricValues' => [['value' => '1'], ['value' => '1'], ['value' => '1']],
                        ],
                        [
                            'dimensionValues' => [['value' => '20260801'], ['value' => '/two']],
                            'metricValues' => [['value' => '2'], ['value' => '2'], ['value' => '2']],
                        ],
                    ],
                    'rowCount' => 3,
                ];
            }

            return [
                'rows' => [
                    [
                        'dimensionValues' => [['value' => '20260801'], ['value' => '/three']],
                        'metricValues' => [['value' => '3'], ['value' => '3'], ['value' => '3']],
                    ],
                ],
                'rowCount' => 3,
            ];
        });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        self::assertSame([0, 2], $offsetsSeen);

        $pageviewUrls = array_map(
            static fn ($metric) => $metric->url,
            array_values(array_filter($metrics, static fn ($metric) => 'ga4.pageviews' === $metric->metric)),
        );

        self::assertSame(
            ['https://example.com/one', 'https://example.com/two', 'https://example.com/three'],
            $pageviewUrls,
        );
    }

    public function testFetchBuildsUrlsFromOriginOnlyForSubdirectoryBasedSites(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));
        $site = new Site('main', 1, ['base' => 'https://example.com/en/']);

        $this->apiClient->method('runReport')->willReturnCallback(static function (Ga4Configuration $configuration, array $body): array {
            if (['name' => 'pagePath'] === ($body['dimensions'][1] ?? null)) {
                return [
                    'rows' => [
                        [
                            'dimensionValues' => [['value' => '20260801'], ['value' => '/en/about']],
                            'metricValues' => [['value' => '1'], ['value' => '1'], ['value' => '1']],
                        ],
                    ],
                ];
            }

            return ['rows' => []];
        });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($site, $range), false);

        self::assertSame('https://example.com/en/about', $metrics[0]->url);
    }

    public function testFetchSkipsRowsWithNonRootRelativePathValues(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));

        $this->apiClient->method('runReport')->willReturnCallback(static function (Ga4Configuration $configuration, array $body): array {
            if (['name' => 'pagePath'] === ($body['dimensions'][1] ?? null)) {
                return [
                    'rows' => [
                        [
                            'dimensionValues' => [['value' => '20260801'], ['value' => '(not set)']],
                            'metricValues' => [['value' => '1'], ['value' => '1'], ['value' => '1']],
                        ],
                    ],
                ];
            }

            return ['rows' => []];
        });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        self::assertSame([], $metrics);
    }

    public function testFetchSkipsRowsWhoseValueContainersAreNotArrays(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));
        $this->apiClient->method('runReport')->willReturn([
            'rows' => [
                ['dimensionValues' => 'broken', 'metricValues' => [['value' => '42']]],
                ['dimensionValues' => [['value' => '20260801'], ['value' => '/about']], 'metricValues' => 'broken'],
            ],
        ]);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));

        self::assertSame([], iterator_to_array($this->subject->fetch($this->site, $range)));
    }

    public function testFetchSkipsRowsWhoseDateIsNotInYmdFormat(): void
    {
        $this->resolver->method('resolve')->willReturn(new Ga4Configuration('123456789', 'test@example.com', 'key'));
        $this->apiClient->method('runReport')->willReturn([
            'rows' => [
                [
                    'dimensionValues' => [['value' => 'not-a-date'], ['value' => '/about']],
                    'metricValues' => [['value' => '42'], ['value' => '10'], ['value' => '123.7']],
                ],
            ],
        ]);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01'));

        self::assertSame([], iterator_to_array($this->subject->fetch($this->site, $range)));
    }

    private function siteWithGoals(): Site
    {
        return new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'goals' => [
                    ['provider' => 'ga4', 'id' => 'generate_lead', 'label' => 'Lead'],
                    ['provider' => 'ga4', 'id' => 'purchase', 'label' => 'Purchase'],
                    // A goal of another provider never reaches GA4's tiles.
                    ['provider' => 'matomo', 'id' => 3, 'label' => 'Newsletter signup'],
                ],
            ],
        ]);
    }
}
