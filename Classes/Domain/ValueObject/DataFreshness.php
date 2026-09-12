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

    public function __construct(
        public DateTimeImmutable $date,
        DateTimeImmutable $today,
    ) {
        $this->isStale = $this->date < $today->modify(sprintf('-%d days', self::STALE_AFTER_DAYS));
    }
}
