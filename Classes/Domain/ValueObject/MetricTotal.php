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

namespace KonradMichalik\Typo3PageMetrics\Domain\ValueObject;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;

/**
 * MetricTotal.
 *
 * One metric summed over one period, in the unit the fact table stores it
 * in and with that unit attached.
 *
 * It exists because the surfaces of this extension hand their numbers on as
 * formatted strings, which is right for a template and wrong for a file: an
 * export has to say 183 and say that 183 is seconds. The unit travels with
 * the value rather than being looked up again by whoever reads it, because
 * a number without its unit is the one thing this table cannot afford to
 * pass around (see MetricUnit).
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MetricTotal
{
    public function __construct(
        public MetricUnit $unit,
        public int $value,
    ) {}
}
