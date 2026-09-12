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

namespace KonradMichalik\Typo3PageMetrics\Persistence;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * ImportStateRepository.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class ImportStateRepository
{
    private const TABLE = 'tx_typo3pagemetrics_import';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
    ) {}

    public function getLastImportedDate(string $provider, string $siteIdentifier): ?DateTimeImmutable
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $value = $queryBuilder
            ->select('last_imported_date')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('provider', $queryBuilder->createNamedParameter($provider)),
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
            )
            ->executeQuery()
            ->fetchOne();

        return false === $value ? null : new DateTimeImmutable((string) $value);
    }

    /**
     * The window this provider has fetched data for on this site, or null
     * when that is not knowable.
     *
     * Callers use it to tell "no number for this day" apart from "no traffic
     * that day", which the fact table itself cannot answer: a provider only
     * emits a metric for a URL that had activity, so a missing row is an
     * ordinary quiet day far more often than it is a gap.
     *
     * Per provider, not per site: an access log added to a site Matomo has
     * been feeding for a year has its own, much shorter history, and a
     * site-wide window would draw that provider's first eleven months as a
     * flat zero - the exact false collapse this exists to prevent.
     */
    public function getCoverage(string $provider, string $siteIdentifier): ?DateRange
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('covered_from_date', 'covered_until_date')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('provider', $queryBuilder->createNamedParameter($provider)),
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row || null === $row['covered_from_date'] || null === $row['covered_until_date']) {
            return null;
        }

        $from = new DateTimeImmutable((string) $row['covered_from_date']);
        $until = new DateTimeImmutable((string) $row['covered_until_date']);

        // DateRange does not validate its own bounds, and an inverted one
        // would mask every day of every series instead of none of them.
        return $from <= $until ? new DateRange($from, $until) : null;
    }

    /**
     * $covered is the window this run has answers for, which is not the
     * same as $date: that one is the resume cursor and deliberately lags a
     * day behind (see the ImportCommand call site). Null means the run
     * returned nothing at all, which establishes no window: an empty answer
     * and an empty history are indistinguishable, so the stored one is left
     * exactly as it was.
     *
     * The stored window only ever grows. A later incremental run starts
     * where the last one stopped, which says nothing about the history
     * already imported before it.
     *
     * $coversFullWindow says whether $covered is the whole retention window
     * rather than an increment on top of earlier runs, which is true for a
     * forced run and for the first run of a provider. It matters when the
     * stored window is unknown: an increment tells us nothing about what
     * came before it, so writing it would shrink an unknown-but-real
     * history down to the last day or two and mask everything else as
     * missing. Unknown stays unknown until a run that can actually answer
     * it, and unknown masks nothing.
     */
    public function markImported(string $provider, string $siteIdentifier, DateTimeImmutable $date, ?DateRange $covered, bool $coversFullWindow): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identity = ['provider' => $provider, 'site_identifier' => $siteIdentifier];
        $existing = $connection->select(['uid', 'covered_from_date', 'covered_until_date'], self::TABLE, $identity)->fetchAssociative();
        $values = [
            'covered_from_date' => $covered?->from->format('Y-m-d'),
            'covered_until_date' => $covered?->until->format('Y-m-d'),
            'last_imported_date' => $date->format('Y-m-d'),
            'tstamp' => $this->context->getPropertyFromAspect('date', 'timestamp') ?? time(),
        ];

        if (false !== $existing) {
            $window = $this->widenWindow($existing, $covered, $coversFullWindow);
            $connection->update(self::TABLE, array_merge($values, $window), ['uid' => $existing['uid']]);

            return;
        }

        $connection->insert(self::TABLE, array_merge($identity, $values));
    }

    /**
     * @param array<string, mixed> $existing
     *
     * @return array{covered_from_date: ?string, covered_until_date: ?string}
     */
    private function widenWindow(array $existing, ?DateRange $covered, bool $coversFullWindow): array
    {
        $existingFrom = null !== $existing['covered_from_date'] ? (string) $existing['covered_from_date'] : null;
        $existingUntil = null !== $existing['covered_until_date'] ? (string) $existing['covered_until_date'] : null;

        if (null === $covered) {
            return ['covered_from_date' => $existingFrom, 'covered_until_date' => $existingUntil];
        }

        if (null === $existingFrom && !$coversFullWindow) {
            return ['covered_from_date' => null, 'covered_until_date' => null];
        }

        $from = $covered->from->format('Y-m-d');
        $until = $covered->until->format('Y-m-d');

        return [
            'covered_from_date' => null !== $existingFrom && $existingFrom < $from ? $existingFrom : $from,
            'covered_until_date' => null !== $existingUntil && $existingUntil > $until ? $existingUntil : $until,
        ];
    }
}
