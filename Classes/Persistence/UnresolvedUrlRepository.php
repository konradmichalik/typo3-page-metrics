<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Persistence;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class UnresolvedUrlRepository
{
    private const TABLE = 'tx_pagemetrics_unresolved';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function record(string $source, \DateTimeImmutable $date, string $url, string $metric, UnresolvedReason $reason, int $value, int $capPerDay): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $dateString = $date->format('Y-m-d');
        $identity = [
            'date' => $dateString,
            'source' => $source,
            // Truncated to match the varchar(40) column, same length as a full SHA-1
            // digest would occupy, without using the disallowed sha1() function.
            'url_hash' => substr(hash('sha256', $url), 0, 40),
            'metric' => $metric,
        ];

        $existingUid = $connection->select(['uid'], self::TABLE, $identity)->fetchOne();

        if (false !== $existingUid) {
            $connection->update(self::TABLE, ['value' => $value, 'reason' => $reason->value, 'url' => $url], ['uid' => $existingUid]);

            return;
        }

        $connection->insert(self::TABLE, array_merge($identity, ['url' => $url, 'reason' => $reason->value, 'value' => $value]));

        $this->trimToCap($source, $dateString, $capPerDay);
    }

    public function countForSite(string $host, \DateTimeImmutable $since): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $likePattern = '%://'.$queryBuilder->escapeLikeWildcards($host).'/%';

        return (int) $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->like('url', $queryBuilder->createNamedParameter($likePattern)),
                $queryBuilder->expr()->gte('date', $queryBuilder->createNamedParameter($since->format('Y-m-d'))),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Keeps the $capPerDay highest-value rows for the day, deletes the rest -
     * the lowest-value entries are the least actionable ones to lose.
     */
    private function trimToCap(string $source, string $date, int $capPerDay): void
    {
        $selectQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $uids = $selectQueryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $selectQueryBuilder->expr()->eq('source', $selectQueryBuilder->createNamedParameter($source)),
                $selectQueryBuilder->expr()->eq('date', $selectQueryBuilder->createNamedParameter($date)),
            )
            ->orderBy('value', 'DESC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        $excessUids = array_slice($uids, $capPerDay);

        if ([] === $excessUids) {
            return;
        }

        $deleteQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $deleteQueryBuilder
            ->delete(self::TABLE)
            ->where($deleteQueryBuilder->expr()->in(
                'uid',
                $deleteQueryBuilder->createNamedParameter($excessUids, Connection::PARAM_INT_ARRAY),
            ))
            ->executeStatement();
    }
}
