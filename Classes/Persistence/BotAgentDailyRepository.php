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
use Doctrine\DBAL\ParameterType;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};

use function hash;
use function substr;

/**
 * BotAgentDailyRepository.
 *
 * Same select-then-insert-or-update upsert style as DailyMetricsRepository,
 * for the same reason (see that class's docblock) - and the same replace,
 * never add, semantics: a rotated log re-imported after a hash change must
 * land on the same total, not double-count on top of it.
 *
 * agent_hash (a 40-char digest of the agent string) carries the identity instead of the
 * agent column itself, keeping the unique index narrow regardless of how
 * long a given agent string is - agent itself is stored purely as display
 * payload, updated alongside hits/hits_suspicious on every upsert() so a
 * hash collision (astronomically unlikely for this cardinality, but not
 * impossible) still self-corrects to the most recently written spelling.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class BotAgentDailyRepository
{
    private const TABLE = 'tx_typo3pagemetrics_bot_agent_daily';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Which named agent made the hits a bot bucket counts, summed over the
     * range and the pages, as bucket => agent => hits.
     *
     * Several buckets in one query rather than one call each, for the same
     * reason DailyMetricsRepository::sumsByMetricAndDimensionPrefix() takes
     * a list of metrics: this answers a popover most readers never open, and
     * a tile row must not pay an aggregate scan per tile for it.
     *
     * No LIMIT, although only the strongest few are ever shown. The agent
     * column holds a needle from BotTaxonomy's curated lists, never a raw
     * User-Agent header, so a bucket has tens of possible values rather than
     * the unbounded set the header itself would give - and the caller needs
     * the whole set anyway to state what the remainder below its cut is made
     * of.
     *
     * Ordered by hits within a bucket so the caller's cut keeps the agents
     * that matter, with the agent name as tie-break so equal counts do not
     * reorder themselves between two renders of the same period.
     *
     * Page-scoped: the page-zero rollup rows this table also carries are
     * only ever returned where a caller asks for page zero itself.
     *
     * @param list<int>    $pageUids
     * @param list<string> $buckets
     *
     * @return array<string, array<string, int>> bucket => agent => hits, only for buckets and agents with at least one row
     */
    public function agentSums(array $pageUids, int $languageUid, array $buckets, DateRange $range): array
    {
        if ([] === $pageUids || [] === $buckets) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('bucket', 'agent')
            ->addSelectLiteral('SUM('.$queryBuilder->quoteIdentifier('hits').') AS total')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in('page_uid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->in('bucket', $queryBuilder->createNamedParameter($buckets, Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->gte('date', $queryBuilder->createNamedParameter($range->from->format('Y-m-d'))),
                $queryBuilder->expr()->lte('date', $queryBuilder->createNamedParameter($range->until->format('Y-m-d'))),
            )
            ->groupBy('bucket', 'agent')
            ->orderBy('bucket')
            ->addOrderBy('total', 'DESC')
            ->addOrderBy('agent')
            ->executeQuery()
            ->fetchAllAssociative();

        $sums = [];
        foreach ($rows as $row) {
            $sums[(string) $row['bucket']][(string) $row['agent']] = (int) $row['total'];
        }

        return $sums;
    }

    public function upsert(string $siteIdentifier, int $pageUid, int $languageUid, string $bucket, string $agent, DateTimeImmutable $date, int $hits, int $hitsSuspicious): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identity = [
            'date' => $date->format('Y-m-d'),
            'site_identifier' => $siteIdentifier,
            'page_uid' => $pageUid,
            'sys_language_uid' => $languageUid,
            'bucket' => $bucket,
            // Truncated to match the char(40) column, same length as a full
            // SHA-1 digest would occupy, without using the disallowed sha1()
            // function - the same pattern UnresolvedUrlRepository uses.
            'agent_hash' => substr(hash('sha256', $agent), 0, 40),
        ];

        $existingUid = $connection->select(['uid'], self::TABLE, $identity)->fetchOne();

        if (false !== $existingUid) {
            $connection->update(self::TABLE, ['agent' => $agent, 'hits' => $hits, 'hits_suspicious' => $hitsSuspicious], ['uid' => $existingUid]);

            return;
        }

        $connection->insert(self::TABLE, array_merge($identity, ['agent' => $agent, 'hits' => $hits, 'hits_suspicious' => $hitsSuspicious]));
    }
}
