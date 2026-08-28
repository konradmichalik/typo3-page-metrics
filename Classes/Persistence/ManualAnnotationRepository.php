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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * ManualAnnotationRepository.
 *
 * Reads tx_typo3pagemetrics_manual_annotation - annotations editors create
 * directly in the TYPO3 backend (a normal TCA-editable record, see
 * Configuration/TCA/tx_typo3pagemetrics_manual_annotation.php) rather than
 * importing them from an external API. Unlike AnnotationRepository, this is
 * read-only and has no upsert(): the records themselves are the source of
 * truth, edited straight through the backend, not written by the import
 * command. Its result is merged with AnnotationRepository's own at every
 * consumer (PageMetricsViewDataBuilder, SiteMetricsOverviewBuilder).
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ManualAnnotationRepository
{
    private const TABLE = 'tx_typo3pagemetrics_manual_annotation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<Annotation>
     */
    public function forSiteAndRange(string $siteIdentifier, DateRange $range): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('uid', 'date', 'text')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->gte('date', $queryBuilder->createNamedParameter($range->from->getTimestamp(), ParameterType::INTEGER)),
                $queryBuilder->expr()->lte('date', $queryBuilder->createNamedParameter($range->until->getTimestamp(), ParameterType::INTEGER)),
            )
            ->orderBy('date', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): Annotation => new Annotation('manual-'.$row['uid'], (new DateTimeImmutable())->setTimestamp((int) $row['date']), (string) $row['text']),
            $rows,
        );
    }
}
