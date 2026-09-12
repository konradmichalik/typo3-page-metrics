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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\Enum;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricGroup;
use PHPUnit\Framework\TestCase;

/**
 * MetricGroupTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MetricGroupTest extends TestCase
{
    public function testTheCasesAreTheTwoAudiencesAMetricCanDescribe(): void
    {
        // A third case would need a third answer to "does this tile belong in
        // the row an editor came for", and there is no such answer: either a
        // human did it or something else did.
        self::assertSame(['audience', 'machine'], array_map(
            static fn (MetricGroup $group): string => $group->value,
            MetricGroup::cases(),
        ));
    }

    public function testTheValuesAreStableEnoughToStoreAndCompare(): void
    {
        self::assertSame(MetricGroup::Audience, MetricGroup::from('audience'));
        self::assertSame(MetricGroup::Machine, MetricGroup::from('machine'));
    }
}
