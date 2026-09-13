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
 * ComparisonBaseline.
 *
 * Which earlier period the selected one is held against: the one
 * immediately before it, or the same stretch of days a month, a quarter or
 * a year earlier.
 *
 * The first option is what every other surface of this extension already
 * shows, and it is the default for exactly that reason. The other three are
 * the reason the compare view is not a second copy of the page view: "is
 * this month different from last month" is a question about noise, while
 * "is this March different from last March" is a question about seasonality,
 * and a page whose traffic is driven by a yearly event answers the two
 * differently.
 *
 * A value object rather than an enum because the baseline is arithmetic on
 * dates and is asked to perform it: reference() is the whole type, and an
 * enum case with a match table beside it would be the same data further
 * from the rule that uses it.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ComparisonBaseline
{
    private function __construct(
        public string $key,
        private int $monthsBack,
    ) {}

    /**
     * Widening order, so the list reads as "how far back do you want to
     * look" rather than as four unrelated options.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self('previous', 0),
            new self('month', 1),
            new self('quarter', 3),
            new self('year', 12),
        ];
    }

    public static function default(): self
    {
        return self::all()[0];
    }

    /**
     * The key arrives from `moduleData`, which is a stored preference that
     * outlives the release that wrote it, so an unknown one is the default
     * rather than an error.
     */
    public static function fromKey(?string $key): self
    {
        foreach (self::all() as $baseline) {
            if ($baseline->key === $key) {
                return $baseline;
            }
        }

        return self::default();
    }

    /**
     * The length is carried over rather than derived from the shifted end,
     * because two totals over windows of different lengths are not
     * comparable and nothing downstream can tell them apart: a February
     * baseline for a 30-day March period would be short by two days that
     * look exactly like two quiet ones.
     */
    public function reference(DateRange $current, int $periodDays): DateRange
    {
        $from = 0 === $this->monthsBack
            ? $current->from->modify(sprintf('-%d days', $periodDays))
            : self::monthsBefore($current->from, $this->monthsBack);

        return new DateRange($from, $from->modify(sprintf('+%d days', $periodDays - 1)));
    }

    /**
     * PHP's relative months overflow into the following one: "-1 month" on
     * 2026-03-31 is 2026-02-31 and therefore 2026-03-03, which lands inside
     * the very period it is meant to be the baseline for. Capped to the last
     * day of the intended month instead, which is the reading a human gives
     * "a month before the 31st".
     */
    private static function monthsBefore(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $shifted = $date->modify(sprintf('-%d months', $months));

        return $shifted->format('j') === $date->format('j') ? $shifted : $shifted->modify('last day of previous month');
    }
}
