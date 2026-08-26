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
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * ImportStateRepository.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class ImportStateRepository
{
    private const TABLE = 'tx_pagemetrics_import';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getLastImportedDate(string $source, string $siteIdentifier): ?DateTimeImmutable
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $value = $queryBuilder
            ->select('last_imported_date')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('source', $queryBuilder->createNamedParameter($source)),
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
            )
            ->executeQuery()
            ->fetchOne();

        return false === $value ? null : new DateTimeImmutable((string) $value);
    }

    public function markImported(string $source, string $siteIdentifier, DateTimeImmutable $date): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identity = ['source' => $source, 'site_identifier' => $siteIdentifier];
        $existingUid = $connection->select(['uid'], self::TABLE, $identity)->fetchOne();
        $values = [
            'last_imported_date' => $date->format('Y-m-d'),
            'tstamp' => $GLOBALS['EXEC_TIME'] ?? time(),
        ];

        if (false !== $existingUid) {
            $connection->update(self::TABLE, $values, ['uid' => $existingUid]);

            return;
        }

        $connection->insert(self::TABLE, array_merge($identity, $values));
    }
}
