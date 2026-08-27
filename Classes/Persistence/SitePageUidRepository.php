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

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};

use function array_map;
use function array_push;

/**
 * SitePageUidRepository.
 *
 * A minimal, explicit breadth-first page tree walk rather than
 * TYPO3\CMS\Backend\Tree\Repository\PageTreeRepository - that class exists
 * to feed the backend page tree UI and carries workspace/permission overlay
 * behaviour that has nothing to do with "which page UIDs belong to this
 * site's data". Iterative (level by level) instead of a recursive CTE so
 * this keeps working unmodified on every DB platform TYPO3 supports.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SitePageUidRepository
{
    private const TABLE = 'pages';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Whether $pageId has at least one non-deleted child - cheaper than
     * descendantsOf() (a single existence check instead of walking the
     * whole subtree) for UI decisions like whether to show a
     * subtree-aggregation option at all.
     */
    public function hasChildren(int $pageId): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return false !== $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<int> $rootPageId itself plus every non-deleted descendant
     */
    public function descendantsOf(int $rootPageId): array
    {
        $uids = [$rootPageId];
        $frontier = [$rootPageId];

        while ([] !== $frontier) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $children = $queryBuilder
                ->select('uid')
                ->from(self::TABLE)
                ->where(
                    $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($frontier, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                )
                ->executeQuery()
                ->fetchFirstColumn();

            $frontier = array_map('intval', $children);
            array_push($uids, ...$frontier);
        }

        return $uids;
    }

    /**
     * @param list<int> $pageUids
     *
     * @return array<int, string> page_uid => title, only for UIDs that still exist
     */
    public function titlesFor(array $pageUids): array
    {
        if ([] === $pageUids) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $titles = [];
        foreach ($rows as $row) {
            $titles[(int) $row['uid']] = (string) $row['title'];
        }

        return $titles;
    }
}
