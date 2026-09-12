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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

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
 * Dates are Unix timestamps here rather than the sibling table's 'Y-m-d'
 * strings, because TCA stores a datetime that way. An empty datetime field
 * is written as 0, not NULL, which is why the range end is tested against 0
 * rather than for nullness.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ManualAnnotationRepository
{
    private const TABLE = 'tx_typo3pagemetrics_manual_annotation';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<int> $ancestorUids
     *
     * @return list<Annotation>
     */
    public function forSiteAndRange(string $siteIdentifier, DateRange $range, ?int $pageUid = null, array $ancestorUids = []): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->select('uid', 'date', 'date_until', 'category', 'page_uid', 'include_subpages', 'text')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->lte('date', $queryBuilder->createNamedParameter($range->until->getTimestamp(), ParameterType::INTEGER)),
                // See AnnotationRepository: a marker overlapping the period
                // belongs in it, not only one starting inside it.
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->gte('date', $queryBuilder->createNamedParameter($range->from->getTimestamp(), ParameterType::INTEGER)),
                    $queryBuilder->expr()->gte('date_until', $queryBuilder->createNamedParameter($range->from->getTimestamp(), ParameterType::INTEGER)),
                ),
            )
            ->orderBy('date', 'ASC');

        if (null !== $pageUid) {
            $queryBuilder->andWhere($this->pageScope($queryBuilder, $pageUid, $ancestorUids));
        }

        return array_map(
            static function (array $row): Annotation {
                $until = (int) $row['date_until'];

                return new Annotation(
                    'manual-'.$row['uid'],
                    (new DateTimeImmutable())->setTimestamp((int) $row['date']),
                    (string) $row['text'],
                    AnnotationCategory::fromStorage(null !== $row['category'] ? (string) $row['category'] : null),
                    $until > 0 ? (new DateTimeImmutable())->setTimestamp($until) : null,
                    (int) $row['page_uid'],
                    (bool) $row['include_subpages'],
                );
            },
            $queryBuilder->executeQuery()->fetchAllAssociative(),
        );
    }

    /**
     * @param list<int> $ancestorUids
     */
    private function pageScope(QueryBuilder $queryBuilder, int $pageUid, array $ancestorUids): string
    {
        $scopes = [
            $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
        ];

        if ([] !== $ancestorUids) {
            $scopes[] = $queryBuilder->expr()->and(
                $queryBuilder->expr()->in('page_uid', $queryBuilder->createNamedParameter($ancestorUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('include_subpages', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)),
            );
        }

        return (string) $queryBuilder->expr()->or(...$scopes);
    }
}
