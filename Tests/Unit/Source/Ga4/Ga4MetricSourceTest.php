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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Source\Ga4;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Source\Ga4\{Ga4ApiClient, Ga4Configuration, Ga4ConfigurationResolver, Ga4MetricSource};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Ga4MetricSourceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Ga4MetricSourceTest extends TestCase
{
    /** @var Ga4ConfigurationResolver&\PHPUnit\Framework\MockObject\MockObject */
    private Ga4ConfigurationResolver $resolver;

    /** @var Ga4ApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private Ga4ApiClient $apiClient;
    private Ga4MetricSource $subject;
    private Site $site;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(Ga4ConfigurationResolver::class);
        $this->apiClient = $this->createMock(Ga4ApiClient::class);
        $this->subject = new Ga4MetricSource($this->resolver, $this->apiClient);
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
            if (['name' => 'landingPage'] === ($body['dimensions'][1] ?? null)) {
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
}
