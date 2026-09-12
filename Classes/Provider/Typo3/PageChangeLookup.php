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

namespace KonradMichalik\Typo3PageMetrics\Provider\Typo3;

use DateTimeImmutable;
use Doctrine\DBAL\{Exception as DBALException, ParameterType};
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Persistence\SitePageUidRepository;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Site\Entity\Site;

use function array_map;
use function array_values;
use function in_array;
use function preg_match;

/**
 * PageChangeLookup.
 *
 * What TYPO3's own tables already say happened to a site's pages inside a
 * window: an edit recorded in sys_history, a redirect created in
 * sys_redirect. Nothing is written for these events at edit time, they are
 * re-derived from records that exist anyway.
 *
 * Not final because the provider's unit tests mock it, the same reason the
 * *ConfigurationResolver classes are not final.
 *
 * Privacy: sys_history rows carry the backend user who made the change
 * (userid, originaluserid) and the changed values themselves
 * (history_data). None of those columns are selected here. A marker says
 * that a page changed, never who changed it or to what, so nothing that
 * could identify an editor can reach the backend module in the first place.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class PageChangeLookup
{
    private const CONTENT_TABLE = 'tt_content';
    private const HISTORY_TABLE = 'sys_history';
    private const PAGES_TABLE = 'pages';
    private const REDIRECT_TABLE = 'sys_redirect';

    /**
     * EXT:redirects writes an internal target in this form, which is the only
     * form that names a page UID without re-resolving a URL.
     */
    private const INTERNAL_TARGET_PREFIX = 't3://page?uid=';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SitePageUidRepository $sitePageUidRepository,
    ) {}

    /**
     * @return list<DailyPageChange>
     */
    public function changesFor(Site $site, DateRange $range): array
    {
        // Both bounds of every query below: the window asked about, and this
        // site's own page tree. sys_history is one of the largest tables in a
        // long-lived installation and holds every table's edits, so neither
        // bound is optional - without the page UIDs a multi-site installation
        // would read the other sites' edits, and without the window it would
        // read years of them.
        $pageUids = $this->sitePageUidRepository->descendantsOf($site->getRootPageId());
        $from = $range->from->setTime(0, 0)->getTimestamp();
        $until = $range->until->setTime(23, 59, 59)->getTimestamp();

        $edits = $this->countByDay([], $this->pageRecordEdits($pageUids, $from, $until));
        $edits = $this->countByDay($edits, $this->contentRecordEdits($pageUids, $from, $until));
        $redirects = $this->countByDay([], $this->redirectCreations($pageUids, $from, $until));

        return [
            ...$this->toChanges($edits, AnnotationCategory::Content),
            ...$this->toChanges($redirects, AnnotationCategory::Seo),
        ];
    }

    /**
     * @param list<int> $pageUids
     *
     * @return iterable<array<string, mixed>>
     */
    private function pageRecordEdits(array $pageUids, int $from, int $until): iterable
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::HISTORY_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        // Joined to pages only to read l10n_parent: a page translation is a
        // record of its own, so an edit to the German version would otherwise
        // land on a UID no tile ever asks about. The edit belongs to the page,
        // whichever language was edited.
        $rows = $queryBuilder
            ->select('page.uid AS page_uid', 'page.l10n_parent AS l10n_parent', 'history.tstamp AS tstamp')
            ->from(self::HISTORY_TABLE, 'history')
            ->innerJoin(
                'history',
                self::PAGES_TABLE,
                'page',
                $queryBuilder->expr()->eq('page.uid', $queryBuilder->quoteIdentifier('history.recuid')),
            )
            ->where(
                $queryBuilder->expr()->eq('history.tablename', $queryBuilder->createNamedParameter(self::PAGES_TABLE)),
                $queryBuilder->expr()->in('history.recuid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->gte('history.tstamp', $queryBuilder->createNamedParameter($from, ParameterType::INTEGER)),
                $queryBuilder->expr()->lte('history.tstamp', $queryBuilder->createNamedParameter($until, ParameterType::INTEGER)),
                $this->liveWorkspaceOnly($queryBuilder),
            )
            ->executeQuery()
            // Streamed rather than fetched as one array: the number of rows
            // grows with how busy the editors were, while the fold they go
            // into is bounded by pages times days.
            ->iterateAssociative();

        foreach ($rows as $row) {
            $translationParent = (int) $row['l10n_parent'];

            yield [
                'page_uid' => $translationParent > 0 ? $translationParent : (int) $row['page_uid'],
                'tstamp' => $row['tstamp'],
            ];
        }
    }

    /**
     * @param list<int> $pageUids
     *
     * @return iterable<array<string, mixed>>
     */
    private function contentRecordEdits(array $pageUids, int $from, int $until): iterable
    {
        // sys_history stores the changed record's UID, not the page it sits
        // on, so the page a content edit belongs to only exists in
        // tt_content.pid - hence the join.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::HISTORY_TABLE);

        // Without removeAll() the container would add tt_content's deleted
        // and hidden restrictions to the join, and both are wrong here: the
        // history row for a content element that was deleted or hidden that
        // day records exactly the change the marker is about.
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('content.pid AS page_uid', 'history.tstamp AS tstamp')
            ->from(self::HISTORY_TABLE, 'history')
            ->innerJoin(
                'history',
                self::CONTENT_TABLE,
                'content',
                $queryBuilder->expr()->eq('content.uid', $queryBuilder->quoteIdentifier('history.recuid')),
            )
            ->where(
                $queryBuilder->expr()->eq('history.tablename', $queryBuilder->createNamedParameter(self::CONTENT_TABLE)),
                $queryBuilder->expr()->in('content.pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->gte('history.tstamp', $queryBuilder->createNamedParameter($from, ParameterType::INTEGER)),
                $queryBuilder->expr()->lte('history.tstamp', $queryBuilder->createNamedParameter($until, ParameterType::INTEGER)),
                $this->liveWorkspaceOnly($queryBuilder),
            )
            ->executeQuery()
            ->iterateAssociative();
    }

    /**
     * @param list<int> $pageUids
     *
     * @return list<array{page_uid: int, tstamp: int}>
     */
    private function redirectCreations(array $pageUids, int $from, int $until): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REDIRECT_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        try {
            $rows = $queryBuilder
                ->select('target', 'createdon')
                ->from(self::REDIRECT_TABLE)
                ->where(
                    $queryBuilder->expr()->gte('createdon', $queryBuilder->createNamedParameter($from, ParameterType::INTEGER)),
                    $queryBuilder->expr()->lte('createdon', $queryBuilder->createNamedParameter($until, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('disabled', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                    // A redirect to an external URL or to a bare path names no
                    // page, and re-resolving one would mean running the whole
                    // URL resolver chain for an event, so only internal
                    // targets are asked for at all.
                    $queryBuilder->expr()->like('target', $queryBuilder->createNamedParameter($queryBuilder->escapeLikeWildcards(self::INTERNAL_TARGET_PREFIX).'%')),
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (DBALException) {
            // typo3/cms-redirects is optional (require-dev only); an
            // installation without it has no sys_redirect table at all, which
            // from here is indistinguishable from "no redirect was created".
            // Same degradation as RedirectFollower::findTarget().
            return [];
        }

        $creations = [];

        foreach ($rows as $row) {
            $pageUid = $this->targetPageUid((string) $row['target']);

            // The page filter runs here rather than in SQL because the page a
            // redirect points at is encoded inside the target string. The
            // query is still bounded: by the window, and to internal targets.
            if (null === $pageUid || !in_array($pageUid, $pageUids, true)) {
                continue;
            }

            $creations[] = ['page_uid' => $pageUid, 'tstamp' => (int) $row['createdon']];
        }

        return $creations;
    }

    private function targetPageUid(string $target): ?int
    {
        if (1 !== preg_match('/^t3:\/\/page\?uid=(\d+)/', $target, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Workspace edits explain nothing about published traffic, and the column
     * is nullable, so a row written without an explicit workspace counts as
     * live rather than being dropped.
     */
    private function liveWorkspaceOnly(QueryBuilder $queryBuilder): string
    {
        return (string) $queryBuilder->expr()->or(
            $queryBuilder->expr()->eq('history.workspace', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            $queryBuilder->expr()->isNull('history.workspace'),
        );
    }

    /**
     * @param array<string, array{pageUid: int, date: string, changes: int}> $counts
     * @param iterable<array<string, mixed>>                                 $rows
     *
     * @return array<string, array{pageUid: int, date: string, changes: int}>
     */
    private function countByDay(array $counts, iterable $rows): array
    {
        foreach ($rows as $row) {
            $pageUid = (int) $row['page_uid'];
            // The day is decided in PHP, not in SQL: turning a Unix timestamp
            // into a calendar day needs a vendor-specific function, and the
            // functional suite runs on SQLite while installations run on
            // MySQL or PostgreSQL.
            $day = (new DateTimeImmutable())->setTimestamp((int) $row['tstamp'])->format('Y-m-d');
            $key = $pageUid.'|'.$day;

            $counts[$key] = [
                'pageUid' => $pageUid,
                'date' => $day,
                'changes' => ($counts[$key]['changes'] ?? 0) + 1,
            ];
        }

        return $counts;
    }

    /**
     * @param array<string, array{pageUid: int, date: string, changes: int}> $counts
     *
     * @return list<DailyPageChange>
     */
    private function toChanges(array $counts, AnnotationCategory $category): array
    {
        return array_values(array_map(
            static fn (array $count): DailyPageChange => new DailyPageChange(
                $count['pageUid'],
                new DateTimeImmutable($count['date']),
                $count['changes'],
                $category,
            ),
            $counts,
        ));
    }
}
