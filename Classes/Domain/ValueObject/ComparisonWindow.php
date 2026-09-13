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

use function max;
use function min;
use function sprintf;

/**
 * ComparisonWindow.
 *
 * The N days before a marker and the N days after it, plus the one question
 * that decides whether the two may be shown side by side at all: is either
 * side made of days nobody has imported yet.
 *
 * A window is arithmetic on dates and knows nothing about metrics, which is
 * why it is a value object and not part of the builder. Whether enough of it
 * exists is asked per provider, because coverage is a property of the
 * provider that fetched the data (see ImportCoverage), so the same window
 * can be complete for Matomo and incomplete for an access log added last
 * week.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ComparisonWindow
{
    private function __construct(
        public DateRange $before,
        public DateRange $after,
        public int $windowDays,
    ) {}

    /**
     * $eventEnd is the last day a marker spans, null for one that fell on a
     * single day. The "after" side starts the day after the event is over: a
     * three-week campaign has no aftermath while it is still running, and
     * counting its own days into either side would measure the event against
     * itself.
     *
     * The event's own day belongs to neither side for the same reason. A
     * rewrite published at four in the afternoon changed half a day, and
     * that half day is the one thing a before/after claim cannot survive
     * being asked about.
     */
    public static function around(DateTimeImmutable $eventStart, ?DateTimeImmutable $eventEnd, int $windowDays): self
    {
        $start = $eventStart->setTime(0, 0);
        // Never before the day it started. date_until has no TCA range check
        // tying it to date, so an editor can save a marker that ends before
        // it begins; taken literally that produces an "after" window reaching
        // back past the marker and an overlap with "before", printed as a
        // straight before/after claim. Annotation::$isRange already refuses
        // to call that a range, and this is the same refusal in date form.
        $end = max($start, ($eventEnd ?? $eventStart)->setTime(0, 0));

        return new self(
            new DateRange($start->modify(sprintf('-%d days', $windowDays)), $start->modify('-1 day')),
            new DateRange($end->modify('+1 day'), $end->modify(sprintf('+%d days', $windowDays))),
            $windowDays,
        );
    }

    /**
     * $latestKnownDay is what to measure against when a provider has no
     * import state: the extension then makes no claim about how far back its
     * history goes (the same stance ImportCoverage::mask() takes), but it
     * still knows that a day which has not happened yet cannot have been
     * imported.
     */
    public function availableBeforeDays(?DateRange $coverage, DateTimeImmutable $latestKnownDay): int
    {
        return $this->coveredDays($this->before, $coverage, $latestKnownDay);
    }

    public function availableAfterDays(?DateRange $coverage, DateTimeImmutable $latestKnownDay): int
    {
        return $this->coveredDays($this->after, $coverage, $latestKnownDay);
    }

    /**
     * Both sides whole, or nothing. A comparison drawn from four of fourteen
     * days after is not a smaller version of the claim, it is a different
     * and much weaker one, and it reads as the same number.
     */
    public function isComplete(?DateRange $coverage, DateTimeImmutable $latestKnownDay): bool
    {
        return $this->windowDays === $this->availableBeforeDays($coverage, $latestKnownDay)
            && $this->windowDays === $this->availableAfterDays($coverage, $latestKnownDay);
    }

    private function coveredDays(DateRange $window, ?DateRange $coverage, DateTimeImmutable $latestKnownDay): int
    {
        $from = $window->from;
        // A day that has not happened yet cannot have been imported, whatever
        // the import state claims about the window it covers.
        $until = min($window->until, $latestKnownDay->setTime(0, 0));

        if (null !== $coverage) {
            $from = max($from, $coverage->from);
            $until = min($until, $coverage->until);
        }

        if ($from > $until) {
            return 0;
        }

        return $from->diff($until)->days + 1;
    }
}
