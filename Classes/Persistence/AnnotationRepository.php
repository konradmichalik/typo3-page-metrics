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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * AnnotationRepository.
 *
 * Upsert is select-then-insert-or-update, same as DailyMetricsRepository -
 * acceptable because the import command runs single-threaded, never
 * concurrently.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class AnnotationRepository
{
    private const TABLE = 'tx_pagemetrics_annotation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function upsert(string $siteIdentifier, string $source, string $annotationId, DateTimeImmutable $date, string $text): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identity = [
            'source' => $source,
            'site_identifier' => $siteIdentifier,
            'annotation_id' => $annotationId,
        ];

        $existingUid = $connection->select(['uid'], self::TABLE, $identity)->fetchOne();

        if (false !== $existingUid) {
            $connection->update(self::TABLE, ['date' => $date->format('Y-m-d'), 'text' => $text], ['uid' => $existingUid]);

            return;
        }

        $connection->insert(self::TABLE, array_merge($identity, ['date' => $date->format('Y-m-d'), 'text' => $text]));
    }

    /**
     * @return list<Annotation>
     */
    public function forSiteAndRange(string $siteIdentifier, DateRange $range): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('annotation_id', 'date', 'text')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->gte('date', $queryBuilder->createNamedParameter($range->from->format('Y-m-d'))),
                $queryBuilder->expr()->lte('date', $queryBuilder->createNamedParameter($range->until->format('Y-m-d'))),
            )
            ->orderBy('date', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): Annotation => new Annotation((string) $row['annotation_id'], new DateTimeImmutable((string) $row['date']), (string) $row['text']),
            $rows,
        );
    }
}
