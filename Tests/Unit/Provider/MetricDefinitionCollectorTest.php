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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricDefinition;
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface, SiteAwareMetricProviderInterface};
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
        $this->subject = new MetricDefinitionCollector();
        $this->site = new Site('main', 1, ['base' => 'https://example.com/']);
    }

    public function testReturnsTheStaticDefinitionsOfAProviderThatHasNoPerSiteOnes(): void
    {
        $provider = $this->createMock(MetricProviderInterface::class);
        $provider->method('getMetricDefinitions')->willReturn([$this->definition('demo.pageviews')]);

        $identifiers = array_map(
            static fn (MetricDefinition $definition): string => $definition->identifier,
            $this->subject->forSite($provider, $this->site),
        );

        self::assertSame(['demo.pageviews'], $identifiers);
    }

    public function testAppendsThePerSiteDefinitionsRatherThanReplacingTheStaticOnes(): void
    {
        $provider = $this->createMock(SiteAwareMetricProviderInterface::class);
        $provider->method('getMetricDefinitions')->willReturn([$this->definition('demo.pageviews')]);
        $provider->method('getSiteMetricDefinitions')->with($this->site)->willReturn([$this->definition('demo.goal.3.conversions')]);

        $identifiers = array_map(
            static fn (MetricDefinition $definition): string => $definition->identifier,
            $this->subject->forSite($provider, $this->site),
        );

        self::assertSame(['demo.pageviews', 'demo.goal.3.conversions'], $identifiers);
    }

    private function definition(string $identifier): MetricDefinition
    {
        return new MetricDefinition($identifier, 'Demo', 'Demo metric.', 'tabler-eye-search', MetricUnit::Count, 10, '#0969da');
    }
}
