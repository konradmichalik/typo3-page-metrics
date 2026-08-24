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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricDefinition;
use PHPUnit\Framework\TestCase;

final class MetricDefinitionTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $definition = new MetricDefinition('matomo.pageviews', 'Page views', 'actions-view', 'count', 10, 5);

        self::assertSame('matomo.pageviews', $definition->identifier);
        self::assertSame('Page views', $definition->label);
        self::assertSame('actions-view', $definition->icon);
        self::assertSame('count', $definition->unit);
        self::assertSame(10, $definition->sortOrder);
        self::assertSame(5, $definition->maxDimensions);
    }

    public function testMaxDimensionsDefaultsToNull(): void
    {
        $definition = new MetricDefinition('matomo.pageviews', 'Page views', 'actions-view', 'count', 10);

        self::assertNull($definition->maxDimensions);
    }
}
