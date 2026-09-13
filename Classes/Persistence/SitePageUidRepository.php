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

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Database\Query\QueryHelper;

use function array_filter;
use function array_flip;
use function array_map;
use function array_push;
use function array_values;
use function in_array;
use function trim;

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
final readonly class SitePageUidRepository
{
    private const TABLE = 'pages';

    /**
     * A page tree deeper than this is a broken pid chain, not a site.
     */
    private const MAX_ROOTLINE_DEPTH = 50;

    public function __construct(
        private ConnectionPool $connectionPool,
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
     * Every page above this one, nearest first, stopping at the tree root.
     *
     * Used to decide which page-scoped annotations reach a page: a marker on
     * an ancestor explains this page only if it said it covers its subtree,
     * so the caller needs the chain rather than just the page.
     *
     * Walks pid by pid rather than using a rootline helper, because the
     * question here is only "which uids are above this one" - no permissions,
     * no overlays, no mount points, and nothing that needs a full page record.
     *
     * @return list<int>
     */
    public function ancestorsOf(int $pageUid): array
    {
        $ancestors = [];
        $current = $pageUid;

        // Bounded rather than trusting the tree to be acyclic: a broken pid
        // chain would otherwise spin here forever, and no real page tree is
        // anywhere near this deep.
        for ($depth = 0; $depth < self::MAX_ROOTLINE_DEPTH; ++$depth) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $parent = $queryBuilder
                ->select('pid')
                ->from(self::TABLE)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($current, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                )
                ->executeQuery()
                ->fetchOne();

            if (false === $parent || 0 === (int) $parent || in_array((int) $parent, $ancestors, true)) {
                break;
            }

            $ancestors[] = (int) $parent;
            $current = (int) $parent;
        }

        return $ancestors;
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

            $frontier = array_map(intval(...), $children);
            array_push($uids, ...$frontier);
        }

        return $uids;
    }

    /**
     * Pages exactly $level steps below $rootPageId - level 1 is $rootPageId's
     * direct children, level 2 their children, and so on - rather than the
     * cumulative subtree descendantsOf() returns. Used where a widget needs
     * to rank pages at one specific depth (e.g. a site's top-level sections)
     * instead of the whole site.
     *
     * @return list<int>
     */
    public function descendantsAtLevel(int $rootPageId, int $level): array
    {
        $frontier = [$rootPageId];

        for ($i = 0; $i < $level && [] !== $frontier; ++$i) {
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

            $frontier = array_map(intval(...), $children);
        }

        return $frontier;
    }

    /**
     * The subset of $pageUids the backend user behind $permsClause is
     * allowed to see, in the order they were asked about.
     *
     * One query for the whole set rather than BackendUtility::readPageAccess()
     * per page: a site-wide table asks this about every page of a site at
     * once, and the per-page call is the same SELECT with the same clause,
     * so doing it row by row would only buy an N+1.
     *
     * $permsClause is the fragment BackendUserAuthentication::getPagePermsClause()
     * produces and is core's own SQL, not user input - the same value
     * readPageAccess() concatenates. stripLogicalOperatorPrefix() because a
     * hook may return it with a leading AND, which is how core itself guards
     * against that (see QueryHelper's own callers).
     *
     * @param list<int> $pageUids
     *
     * @return list<int>
     */
    public function readableUids(array $pageUids, string $permsClause): array
    {
        if ([] === $pageUids || '' === trim($permsClause)) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $readable = array_map(intval(...), $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                QueryHelper::stripLogicalOperatorPrefix($permsClause),
            )
            ->executeQuery()
            ->fetchFirstColumn());

        // The caller's order, not the database's: the caller built the list
        // from a tree walk and a filter must not quietly reshuffle it.
        //
        // Flipped to a lookup rather than scanned with in_array(): this is
        // called with every page of a site, so a linear scan per page is
        // quadratic on exactly the surface that grows. Twenty thousand pages
        // is four hundred million comparisons, which is a module render that
        // times out rather than one that is slow.
        $lookup = array_flip($readable);

        return array_values(array_filter($pageUids, static fn (int $pageUid): bool => isset($lookup[$pageUid])));
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
