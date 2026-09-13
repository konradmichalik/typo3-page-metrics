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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, RecordReference};
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * RecordDailyMetricsRepository.
 *
 * DailyMetricsRepository's sibling for facts about a record rather than a
 * page. Same upsert shape and the same reason for it: select-then-insert-or-
 * update rather than a platform-specific "ON DUPLICATE KEY UPDATE", which is
 * acceptable because the import command runs single-threaded.
 *
 * A day without a row reads as zero, not as unknown, for the same reason it
 * does for pages: a provider only emits a metric for a URL that had activity,
 * so a quiet day simply has no row. Whether the day was imported at all is a
 * property of the site's import state, not of this table.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class RecordDailyMetricsRepository
{
    private const TABLE = 'tx_typo3pagemetrics_record_daily';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function upsert(RecordReference $record, int $languageUid, string $provider, DateTimeImmutable $date, string $metric, int $value, ?string $dimension = null): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identity = [
            'date' => $date->format('Y-m-d'),
            'record_table' => $record->table,
            'record_uid' => $record->uid,
            'sys_language_uid' => $languageUid,
            'provider' => $provider,
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
    public function dailySeries(RecordReference $record, int $languageUid, string $metric, DateRange $range, string $dimension = ''): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('date', 'value')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('record_table', $queryBuilder->createNamedParameter($record->table)),
                $queryBuilder->expr()->eq('record_uid', $queryBuilder->createNamedParameter($record->uid, ParameterType::INTEGER)),
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
