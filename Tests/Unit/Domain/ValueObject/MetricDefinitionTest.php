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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\ValueObject;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
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
        $definition = new MetricDefinition('matomo.pageviews', 'Page views', 'The number of times this page was viewed.', 'actions-view', MetricUnit::Count, 10, '#0969da', 5);

        self::assertSame('matomo.pageviews', $definition->identifier);
        self::assertSame('Page views', $definition->label);
        self::assertSame('The number of times this page was viewed.', $definition->description);
        self::assertSame('actions-view', $definition->icon);
        self::assertSame(MetricUnit::Count, $definition->unit);
        self::assertSame(10, $definition->sortOrder);
        self::assertSame('#0969da', $definition->color);
        self::assertSame(5, $definition->maxDimensions);
    }

    public function testMaxDimensionsDefaultsToNull(): void
    {
        $definition = new MetricDefinition('matomo.pageviews', 'Page views', 'The number of times this page was viewed.', 'actions-view', MetricUnit::Count, 10, '#0969da');

        self::assertNull($definition->maxDimensions);
    }
}
