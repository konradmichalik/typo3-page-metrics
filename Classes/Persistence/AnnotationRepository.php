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
    private const TABLE = 'tx_typo3pagemetrics_annotation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Takes the whole Annotation rather than its fields one at a time: it
     * now carries seven of them, and a call site passing seven positional
     * arguments is a transposition waiting to happen.
     */
    public function upsert(string $siteIdentifier, string $provider, Annotation $annotation): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identity = [
            'provider' => $provider,
            'site_identifier' => $siteIdentifier,
            'annotation_id' => $annotation->id,
        ];

        $values = [
            'date' => $annotation->date->format('Y-m-d'),
            'date_until' => $annotation->dateUntil?->format('Y-m-d'),
            'category' => $annotation->category->value,
            'page_uid' => $annotation->pageUid,
            'include_subpages' => $annotation->includeSubpages ? 1 : 0,
            'text' => $annotation->text,
        ];

        $existingUid = $connection->select(['uid'], self::TABLE, $identity)->fetchOne();

        if (false !== $existingUid) {
            $connection->update(self::TABLE, $values, ['uid' => $existingUid]);

            return;
        }

        $connection->insert(self::TABLE, array_merge($identity, $values));
    }

    /**
     * $pageUid and $ancestorUids narrow the result to the markers that
     * explain one page: the site-wide ones, this page's own, and those on an
     * ancestor that said they cover its subtree. Passing null for $pageUid
     * asks for everything, which is what the site-wide dashboard wants.
     *
     * @param list<int> $ancestorUids
     *
     * @return list<Annotation>
     */
    public function forSiteAndRange(string $siteIdentifier, DateRange $range, ?int $pageUid = null, array $ancestorUids = []): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->select('annotation_id', 'date', 'date_until', 'category', 'page_uid', 'include_subpages', 'text')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                // A marker overlapping the period belongs in it, not only one
                // starting inside it: a campaign that began last month and is
                // still running explains this month's numbers.
                $queryBuilder->expr()->lte('date', $queryBuilder->createNamedParameter($range->until->format('Y-m-d'))),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->gte('date', $queryBuilder->createNamedParameter($range->from->format('Y-m-d'))),
                    $queryBuilder->expr()->gte('date_until', $queryBuilder->createNamedParameter($range->from->format('Y-m-d'))),
                ),
            )
            ->orderBy('date', 'ASC');

        if (null !== $pageUid) {
            $queryBuilder->andWhere($this->pageScope($queryBuilder, $pageUid, $ancestorUids));
        }

        return array_map(
            static fn (array $row): Annotation => new Annotation(
                (string) $row['annotation_id'],
                new DateTimeImmutable((string) $row['date']),
                (string) $row['text'],
                AnnotationCategory::fromStorage(null !== $row['category'] ? (string) $row['category'] : null),
                null !== $row['date_until'] ? new DateTimeImmutable((string) $row['date_until']) : null,
                (int) $row['page_uid'],
                (bool) $row['include_subpages'],
            ),
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
            // An ancestor's marker only reaches down here if it said it
            // should. The page's own marker needs no such permission.
            $scopes[] = $queryBuilder->expr()->and(
                $queryBuilder->expr()->in('page_uid', $queryBuilder->createNamedParameter($ancestorUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('include_subpages', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)),
            );
        }

        return (string) $queryBuilder->expr()->or(...$scopes);
    }
}
