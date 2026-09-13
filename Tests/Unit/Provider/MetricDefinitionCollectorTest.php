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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Provider;

use KonradMichalik\Typo3PageMetrics\Configuration\{GoalConfigurationParser, SiteConfigurationReader};
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricGroup, MetricPolarity, MetricUnit};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricDefinition;
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface, SiteAwareMetricProviderInterface};
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * MetricDefinitionCollectorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MetricDefinitionCollectorTest extends TestCase
{
    private MetricDefinitionCollector $subject;
    private Site $site;

    protected function setUp(): void
    {
        $this->subject = new MetricDefinitionCollector(new SiteConfigurationReader(new GoalConfigurationParser()));
        $this->site = new Site('main', 1, ['base' => 'https://example.com/']);
    }

    public function testReturnsTheStaticDefinitionsOfAProviderThatHasNoPerSiteOnes(): void
    {
        $provider = $this->createMock(MetricProviderInterface::class);
        $provider->method('getMetricDefinitions')->willReturn([$this->definition('demo.pageviews')]);

        self::assertSame(['demo.pageviews'], $this->identifiers($provider, $this->site));
    }

    public function testAppendsThePerSiteDefinitionsRatherThanReplacingTheStaticOnes(): void
    {
        $provider = $this->createMock(SiteAwareMetricProviderInterface::class);
        $provider->method('getMetricDefinitions')->willReturn([$this->definition('demo.pageviews')]);
        $provider->method('getSiteMetricDefinitions')->with($this->site)->willReturn([$this->definition('demo.goal.3.conversions')]);

        self::assertSame(['demo.pageviews', 'demo.goal.3.conversions'], $this->identifiers($provider, $this->site));
    }

    public function testReturnsEverythingTheProviderOffersWhenTheSiteNamesNoMetrics(): void
    {
        $site = $this->siteWith(['accesslog' => ['pathPattern' => '/var/log/nginx/access.log*']]);

        self::assertSame(
            ['accesslog.pageviews', 'bot.search'],
            $this->identifiers($this->accessLogLikeProvider(), $site),
        );
    }

    public function testKeepsOnlyTheMetricsTheSiteNamedForThatProvider(): void
    {
        $site = $this->siteWith(['accesslog' => ['pathPattern' => '/var/log/nginx/access.log*', 'metrics' => ['bot.search']]]);

        self::assertSame(['bot.search'], $this->identifiers($this->accessLogLikeProvider(), $site));
    }

    public function testAGroupNameStandsForEveryMetricTheProviderPutsInThatGroup(): void
    {
        $site = $this->siteWith(['accesslog' => ['pathPattern' => '/var/log/nginx/access.log*', 'metrics' => ['machine']]]);

        self::assertSame(['bot.search'], $this->identifiers($this->accessLogLikeProvider(), $site));
    }

    public function testIgnoresAnIdentifierThisProviderDoesNotOffer(): void
    {
        $site = $this->siteWith(['accesslog' => ['metrics' => ['bot.search', 'matomo.pageviews']]]);

        self::assertSame(['bot.search'], $this->identifiers($this->accessLogLikeProvider(), $site));
    }

    public function testTheListOfOneProviderLeavesAnotherProviderUntouched(): void
    {
        $site = $this->siteWith(['accesslog' => ['metrics' => ['machine']]]);

        $matomo = $this->createMock(MetricProviderInterface::class);
        $matomo->method('getIdentifier')->willReturn('matomo');
        $matomo->method('getMetricDefinitions')->willReturn([$this->definition('matomo.pageviews')]);

        self::assertSame(['matomo.pageviews'], $this->identifiers($matomo, $site));
    }

    public function testFiltersThePerSiteDefinitionsByTheSameList(): void
    {
        $site = $this->siteWith(['matomo' => ['metrics' => ['matomo.pageviews']]]);

        $provider = $this->createMock(SiteAwareMetricProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('matomo');
        $provider->method('getMetricDefinitions')->willReturn([$this->definition('matomo.pageviews')]);
        $provider->method('getSiteMetricDefinitions')->willReturn([$this->definition('matomo.goal.3.conversions')]);

        self::assertSame(['matomo.pageviews'], $this->identifiers($provider, $site));
    }

    public function testStillReportsAProviderAsSupportingWhenItsMetricsAreFilteredOut(): void
    {
        $site = $this->siteWith(['accesslog' => ['metrics' => ['machine']]]);

        $provider = $this->accessLogLikeProvider();
        $provider->method('supports')->willReturn(true);

        self::assertSame([$provider], $this->subject->supporting([$provider], $site));
    }

    public function testThePrimaryMetricIsTheFirstThingTheSiteCountsBySortOrder(): void
    {
        $provider = $this->supportingProvider(
            'demo',
            $this->definition('demo.visits', MetricGroup::Audience, 20),
            $this->definition('demo.pageviews', MetricGroup::Audience, 10),
        );

        self::assertSame('demo.pageviews', $this->subject->primaryFor([$provider], $this->site)?->identifier);
    }

    public function testMachineTrafficIsNeverPrimaryEvenWhenItSortsFirst(): void
    {
        // Every surface that names a primary metric is answering "how is this
        // page doing with people", which is not a question a crawler count
        // answers.
        $provider = $this->supportingProvider(
            'demo',
            $this->definition('bot.search', MetricGroup::Machine, 10),
            $this->definition('demo.pageviews', MetricGroup::Audience, 20),
        );

        self::assertSame('demo.pageviews', $this->subject->primaryFor([$provider], $this->site)?->identifier);
    }

    public function testAMetricThatDoesNotCountIsNeverPrimaryEvenWhenItSortsFirst(): void
    {
        $provider = $this->supportingProvider(
            'demo',
            $this->definition('demo.goal.revenue', MetricGroup::Audience, 10, MetricUnit::Currency),
            $this->definition('demo.pageviews', MetricGroup::Audience, 20),
        );

        self::assertSame('demo.pageviews', $this->subject->primaryFor([$provider], $this->site)?->identifier);
    }

    public function testASiteWithNothingCountableHasNoPrimaryMetric(): void
    {
        // The answer a site declaring only rates gets, and every caller has
        // to treat it as it treats a site with no provider at all.
        $provider = $this->supportingProvider('demo', $this->definition('demo.avg_time', MetricGroup::Audience, 10, MetricUnit::Seconds));

        self::assertNull($this->subject->primaryFor([$provider], $this->site));
    }

    public function testASiteWithNoProviderHasNoPrimaryMetric(): void
    {
        $provider = $this->createMock(MetricProviderInterface::class);
        $provider->method('supports')->willReturn(false);
        $provider->method('getMetricDefinitions')->willReturn([$this->definition('demo.pageviews')]);

        self::assertNull($this->subject->primaryFor([$provider], $this->site));
    }

    public function testThePrimaryMetricIsTheFirstAcrossEveryProviderTheSiteHas(): void
    {
        $first = $this->supportingProvider('demo', $this->definition('demo.pageviews', MetricGroup::Audience, 20));
        $second = $this->supportingProvider('other', $this->definition('other.pageviews', MetricGroup::Audience, 10));

        self::assertSame('other.pageviews', $this->subject->primaryFor([$first, $second], $this->site)?->identifier);
    }

    public function testThePrimaryMetricRespectsTheSitesOwnMetricList(): void
    {
        $site = $this->siteWith(['accesslog' => ['metrics' => ['bot.search']]]);

        $provider = $this->supportingProvider(
            'accesslog',
            $this->definition('accesslog.pageviews', MetricGroup::Audience, 10),
            $this->definition('bot.search', MetricGroup::Machine, 50),
        );

        self::assertNull($this->subject->primaryFor([$provider], $site));
    }

    public function testNamesTheProviderAMetricOfThisSiteCameFrom(): void
    {
        $first = $this->supportingProvider('demo', $this->definition('demo.pageviews'));
        $second = $this->supportingProvider('other', $this->definition('other.pageviews'));

        self::assertSame($second, $this->subject->providerOf([$first, $second], $this->site, 'other.pageviews'));
    }

    public function testNamesNoProviderForAMetricThisSiteDoesNotHave(): void
    {
        $provider = $this->supportingProvider('demo', $this->definition('demo.pageviews'));

        self::assertNull($this->subject->providerOf([$provider], $this->site, 'other.pageviews'));
    }

    /**
     * @param array<string, array<string, mixed>> $providers
     */
    private function siteWith(array $providers): Site
    {
        return new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => true, 'providers' => $providers],
        ]);
    }

    private function accessLogLikeProvider(): MetricProviderInterface&MockObject
    {
        $provider = $this->createMock(MetricProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('accesslog');
        $provider->method('getMetricDefinitions')->willReturn([
            $this->definition('accesslog.pageviews'),
            $this->definition('bot.search', MetricGroup::Machine),
        ]);

        return $provider;
    }

    /**
     * @return list<string>
     */
    private function identifiers(MetricProviderInterface $provider, Site $site): array
    {
        return array_map(
            static fn (MetricDefinition $definition): string => $definition->identifier,
            $this->subject->forSite($provider, $site),
        );
    }

    private function definition(string $identifier, MetricGroup $group = MetricGroup::Audience, int $sortOrder = 10, MetricUnit $unit = MetricUnit::Count): MetricDefinition
    {
        return new MetricDefinition($identifier, 'Demo', 'Demo metric.', 'tabler-eye-search', $unit, $sortOrder, '#0969da', MetricPolarity::HigherIsBetter, $group);
    }

    private function supportingProvider(string $identifier, MetricDefinition ...$definitions): MetricProviderInterface&MockObject
    {
        $provider = $this->createMock(MetricProviderInterface::class);
        $provider->method('getIdentifier')->willReturn($identifier);
        $provider->method('supports')->willReturn(true);
        $provider->method('getMetricDefinitions')->willReturn($definitions);

        return $provider;
    }
}
