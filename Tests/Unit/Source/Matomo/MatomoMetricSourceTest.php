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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Source\Matomo;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Source\Matomo\{MatomoApiClient, MatomoConfiguration, MatomoConfigurationResolver, MatomoMetricSource};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

final class MatomoMetricSourceTest extends TestCase
{
    /** @var MatomoConfigurationResolver&\PHPUnit\Framework\MockObject\MockObject */
    private MatomoConfigurationResolver $resolver;

    /** @var MatomoApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private MatomoApiClient $apiClient;
    private MatomoMetricSource $subject;
    private Site $site;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(MatomoConfigurationResolver::class);
        $this->apiClient = $this->createMock(MatomoApiClient::class);
        $this->subject = new MatomoMetricSource($this->resolver, $this->apiClient);
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

    public function testFetchYieldsPageviewsAndVisitsPerRowPerDay(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn([
            ['label' => '/foo', 'url' => 'https://example.com/foo', 'nb_hits' => 10, 'nb_visits' => 7],
            ['label' => '/bar', 'url' => 'https://example.com/bar', 'nb_hits' => 3, 'nb_visits' => 2],
        ]);

        $range = new DateRange(new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-01'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        self::assertCount(4, $metrics);
        self::assertSame('https://example.com/foo', $metrics[0]->url);
        self::assertSame('matomo.pageviews', $metrics[0]->metric);
        self::assertSame(10, $metrics[0]->value);
        self::assertSame('matomo.visits', $metrics[1]->metric);
        self::assertSame(7, $metrics[1]->value);
        self::assertSame('https://example.com/bar', $metrics[2]->url);
    }

    public function testFetchYieldsOneRowPairPerDayInTheRange(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn([
            ['label' => '/foo', 'url' => 'https://example.com/foo', 'nb_hits' => 1, 'nb_visits' => 1],
        ]);

        $range = new DateRange(new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-02'));
        $metrics = iterator_to_array($this->subject->fetch($this->site, $range), false);

        self::assertCount(4, $metrics);
        self::assertSame('2026-08-01', $metrics[0]->date->format('Y-m-d'));
        self::assertSame('2026-08-02', $metrics[2]->date->format('Y-m-d'));
    }

    public function testFetchYieldsNothingWhenNotConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(null);

        $range = new DateRange(new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-01'));

        self::assertSame([], iterator_to_array($this->subject->fetch($this->site, $range)));
    }

    public function testFetchCallsApiOncePerCalendarDayWithPeriodDayAndFlat(): void
    {
        $configuration = new MatomoConfiguration('https://matomo.example.com/', 3, 'token');
        $this->resolver->method('resolve')->willReturn($configuration);

        $calls = [];
        $this->apiClient->expects(self::exactly(2))
            ->method('request')
            ->willReturnCallback(function (MatomoConfiguration $passedConfiguration, array $parameters) use (&$calls, $configuration) {
                self::assertSame($configuration, $passedConfiguration);
                $calls[] = $parameters;

                return [];
            });

        $range = new DateRange(new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-02'));
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
}
