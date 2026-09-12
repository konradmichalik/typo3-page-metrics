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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\ValueObject;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricPolarity, MetricUnit};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricDefinition;
use PHPUnit\Framework\TestCase;

/**
 * MetricDefinitionTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MetricDefinitionTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $definition = new MetricDefinition('matomo.bounce_rate', 'Bounce rate', 'The share of visits that left immediately.', 'actions-view', MetricUnit::Percent, 10, '#0969da', MetricPolarity::LowerIsBetter);

        self::assertSame('matomo.bounce_rate', $definition->identifier);
        self::assertSame('Bounce rate', $definition->label);
        self::assertSame('The share of visits that left immediately.', $definition->description);
        self::assertSame('actions-view', $definition->icon);
        self::assertSame(MetricUnit::Percent, $definition->unit);
        self::assertSame(10, $definition->sortOrder);
        self::assertSame('#0969da', $definition->color);
        self::assertSame(MetricPolarity::LowerIsBetter, $definition->polarity);
    }

    public function testPolarityDefaultsToHigherIsBetter(): void
    {
        $definition = new MetricDefinition('matomo.pageviews', 'Page views', 'The number of times this page was viewed.', 'actions-view', MetricUnit::Count, 10, '#0969da');

        self::assertSame(MetricPolarity::HigherIsBetter, $definition->polarity);
    }
}
