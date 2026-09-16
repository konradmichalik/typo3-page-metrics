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

use function sprintf;

/**
 * DataFreshness.
 *
 * The last day a surface has numbers for, so it can say so instead of
 * letting the reader assume the figures are current. The import runs on a
 * schedule, and a scheduler that has stopped is otherwise invisible from the
 * backend: the tiles keep rendering the last numbers they were given,
 * indefinitely and without complaint.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DataFreshness
{
    /**
     * Today's own data is normally still missing (the import marks a day
     * complete only once it has elapsed) and yesterday's may not have run
     * yet depending on the scheduler's time of day. Two days behind is the
     * first point at which "no run has happened" is the better explanation
     * than "the run has not happened yet".
     */
    private const STALE_AFTER_DAYS = 2;

    public bool $isStale;

    /**
     * How far behind today the last covered day is, in whole days: 0 for
     * today, 1 for yesterday, and so on upwards.
     *
     * A bare date said what the figures cover without saying what that
     * means, and "2026-09-15" is only readable as current by someone who
     * knows today's date and how the import is scheduled. The age is the
     * part a reader can act on, so it is derived here rather than left for
     * each surface to work out from the date it was handed.
     *
     * Compared at day granularity, with both times dropped: the date comes
     * from the import's own cursor and carries whatever time the run wrote,
     * and an hour's difference must not turn "today" into a day behind.
     *
     * Never negative. A cursor ahead of today is a clock or timezone
     * disagreement between the importing process and the rendering one, not
     * data from the future, and "0" reads as the "up to date" it effectively
     * is rather than as a day count pointing the wrong way.
     */
    public int $daysBehind;

    public function __construct(
        public DateTimeImmutable $date,
        DateTimeImmutable $today,
    ) {
        $this->isStale = $this->date < $today->modify(sprintf('-%d days', self::STALE_AFTER_DAYS));

        $difference = $this->date->setTime(0, 0)->diff($today->setTime(0, 0));
        $this->daysBehind = 1 === $difference->invert ? 0 : $difference->days;
    }
}
