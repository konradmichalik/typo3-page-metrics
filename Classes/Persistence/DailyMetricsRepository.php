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

use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * DailyMetricsRepository.
 *
 * Upsert is select-then-insert-or-update rather than a platform-specific
 * "ON DUPLICATE KEY UPDATE" so this works unmodified on every DB platform
 * TYPO3 supports (MySQL/MariaDB/PostgreSQL/SQLite) - acceptable because
 * the import command runs single-threaded, never concurrently.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class DailyMetricsRepository
{
    private const TABLE = 'tx_pagemetrics_daily';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function upsert(int $pageUid, int $languageUid, string $source, DateTimeImmutable $date, string $metric, int $value, ?string $dimension = null): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identity = [
            'date' => $date->format('Y-m-d'),
            'page_uid' => $pageUid,
            'sys_language_uid' => $languageUid,
            'source' => $source,
            'metric' => $metric,
            'dimension' => $dimension ?? '',
        ];

        $existingUid = $connection->select(['uid'], self::TABLE, $identity)->fetchOne();

        if (false !== $existingUid) {
            $connection->update(self::TABLE, ['value' => $value], ['uid' => $existingUid]);

            return;
        }

        $connection->insert(self::TABLE, array_merge($identity, ['value' => $value]));
    }

    /**
     * @return array<string, int> Y-m-d => value, zero-filled for days without a row
     */
    public function dailySeries(int $pageUid, int $languageUid, string $metric, DateRange $range, string $dimension = ''): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('date', 'value')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('metric', $queryBuilder->createNamedParameter($metric)),
                $queryBuilder->expr()->eq('dimension', $queryBuilder->createNamedParameter($dimension)),
                $queryBuilder->expr()->gte('date', $queryBuilder->createNamedParameter($range->from->format('Y-m-d'))),
                $queryBuilder->expr()->lte('date', $queryBuilder->createNamedParameter($range->until->format('Y-m-d'))),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[substr((string) $row['date'], 0, 10)] = (int) $row['value'];
        }

        $series = [];
        foreach ($range->days() as $day) {
            $key = $day->format('Y-m-d');
            $series[$key] = $byDate[$key] ?? 0;
        }

        return $series;
    }
}
