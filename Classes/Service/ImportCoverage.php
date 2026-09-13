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

namespace KonradMichalik\Typo3PageMetrics\Service;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Persistence\ImportStateRepository;

/**
 * ImportCoverage.
 *
 * Decides which days of a series the extension has an answer for at all.
 *
 * A daily series is zero-filled by DailyMetricsRepository, and for a day
 * inside the imported window that zero is the truth: providers only emit a
 * metric for a URL that had activity, so an ordinary quiet day has no row.
 * Outside that window the same zero is a fabrication, and drawing it is what
 * made the first day of an import history, or a period reaching back further
 * than the data does, read as a traffic collapse.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ImportCoverage
{
    /**
     * Cached per instance: a page-header render asks for the same provider
     * once per tile, and this service is request-scoped like everything
     * else in the container.
     *
     * @var array<string, ?DateRange>
     */
    private array $cache = [];

    public function __construct(
        private readonly ImportStateRepository $importStateRepository,
    ) {}

    public function forProvider(string $provider, string $siteIdentifier): ?DateRange
    {
        return $this->cache[$provider.'|'.$siteIdentifier] ??= $this->importStateRepository->getCoverage($provider, $siteIdentifier);
    }

    /**
     * Whether a comparison against $range can be made at all: every one of
     * its days has to be inside the imported window. The sum of an uncovered
     * range is built out of the same fabricated zeros mask() blanks out of
     * the sparkline, and a tile comparing against one reports a page that has
     * existed for years as new.
     *
     * A partly covered range is refused too. The honest default: a partial
     * sum understates by exactly the amount nobody can quantify, so the
     * comparison would be wrong by an unknown margin, and a comparison that
     * is wrong by an unknown margin is worse than none.
     */
    public function covers(?DateRange $coverage, DateRange $range): bool
    {
        if (null === $coverage) {
            // Same claim as mask(): without import state the extension knows
            // nothing about its own window, so it withholds nothing either.
            return true;
        }

        return $range->from->format('Y-m-d') >= $coverage->from->format('Y-m-d')
            && $range->until->format('Y-m-d') <= $coverage->until->format('Y-m-d');
    }

    /**
     * @param array<string, int> $series Y-m-d => value, zero-filled
     *
     * @return array<string, int|null> the same series with every uncovered day replaced by null
     */
    public function mask(array $series, ?DateRange $coverage): array
    {
        if (null === $coverage) {
            // No import state at all: the extension does not know what it
            // covers, so it makes no claim about it. Marking every day as
            // missing would be the same kind of over-claiming in the other
            // direction, and it would blank out any data seeded straight
            // into the fact table (demo fixtures, a manual restore).
            return $series;
        }

        $from = $coverage->from->format('Y-m-d');
        $until = $coverage->until->format('Y-m-d');
        $masked = [];

        foreach ($series as $date => $value) {
            $masked[$date] = $date >= $from && $date <= $until ? $value : null;
        }

        return $masked;
    }
}
