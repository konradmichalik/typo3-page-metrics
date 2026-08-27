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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\RateMetricDefinition;
use PHPUnit\Framework\TestCase;

/**
 * RateMetricDefinitionTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RateMetricDefinitionTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $definition = new RateMetricDefinition(
            'matomo.avg_time_on_page',
            'Avg. time on page',
            'The average time visitors spent on this page.',
            'tabler-clock',
            MetricUnit::Seconds,
            30,
            '#bf8700',
            'matomo.time_spent_seconds',
            'matomo.visits',
        );

        self::assertSame('matomo.avg_time_on_page', $definition->identifier);
        self::assertSame('Avg. time on page', $definition->label);
        self::assertSame('The average time visitors spent on this page.', $definition->description);
        self::assertSame('tabler-clock', $definition->icon);
        self::assertSame(MetricUnit::Seconds, $definition->unit);
        self::assertSame(30, $definition->sortOrder);
        self::assertSame('#bf8700', $definition->color);
        self::assertSame('matomo.time_spent_seconds', $definition->numerator);
        self::assertSame('matomo.visits', $definition->denominator);
    }
}
