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

use DateTimeImmutable;

/**
 * DateRange.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
    ) {}

    /**
     * Whether the two ranges share at least one day. Asked where two periods
     * are held against each other: a baseline that moves only the start back
     * can still land inside the period it is the baseline for, and a
     * comparison of a period with itself is not one.
     */
    public function overlaps(self $other): bool
    {
        return $this->from <= $other->until && $other->from <= $this->until;
    }

    /**
     * @return iterable<DateTimeImmutable> one entry per calendar day, inclusive of both ends
     */
    public function days(): iterable
    {
        $current = $this->from->setTime(0, 0);
        $last = $this->until->setTime(0, 0);

        while ($current <= $last) {
            yield $current;
            $current = $current->modify('+1 day');
        }
    }
}
