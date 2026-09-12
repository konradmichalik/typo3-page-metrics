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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Provider\Matomo\{MatomoApiClient, MatomoConfiguration, MatomoConfigurationResolver, MatomoMetricProvider};
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
        $this->subject = new MatomoMetricProvider($this->resolver, $this->apiClient);
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
        $this->apiClient->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(static function (MatomoConfiguration $passedConfiguration, array $parameters) use (&$calls, $configuration) {
                self::assertSame($configuration, $passedConfiguration);
                $calls[] = $parameters;

                return [];
            });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-02'));
        iterator_to_array($this->subject->fetch($this->site, $range));

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

        self::assertCount(2, $calls);

        foreach ($calls as $call) {
            self::assertArrayHasKey('filter_limit', $call);
            self::assertSame(-1, $call['filter_limit']);
        }
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
}
