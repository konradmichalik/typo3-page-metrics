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

use function array_map;
use function array_push;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function sort;
use function sprintf;

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

    /**
     * How many pages a collapsed marker names before it resorts to counting
     * the rest.
     */
    private const COLLAPSE_NAMED_PAGES = 3;

    /**
     * The page titles are only ever read for the collapsed markers' own
     * text, which is why this repository knows about pages at all: a summary
     * that could not name a single page would be barely better than the row
     * of ticks it replaces.
     */
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SitePageUidRepository $pageUidRepository,
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
     * Everything, but collapsed: without a page, the derived per-page
     * markers of one day and one kind come back as one marker saying how
     * many pages it stands for. On a site where ten pages are touched every
     * day, ten ticks a day only ever say "people edited things", while the
     * page module still gets each marker whole, because there one marker a
     * day on the page in front of you is the signal you came for.
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

        $annotations = array_map(
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

        return null === $pageUid ? $this->collapsePerDay($annotations) : $annotations;
    }

    /**
     * Only a marker about exactly one page on exactly one day takes part. A
     * marker covering a subtree or a span of days is making a statement
     * larger than a day's edit, and a site-wide one (an authored release,
     * a campaign) is a statement about the site itself - folding either into
     * a count would lose the very thing it was written to say.
     */
    private static function isCollapsible(Annotation $annotation): bool
    {
        return $annotation->pageUid > 0 && !$annotation->isRange && !$annotation->includeSubpages;
    }

    /**
     * Kept as a separate group per kind: a day's content edits and its new
     * redirects are two different facts, and merging them would also break
     * the chart widget's marker-category setting, which narrows by exactly
     * that kind.
     *
     * @param list<Annotation> $annotations
     *
     * @return list<Annotation>
     */
    private function collapsePerDay(array $annotations): array
    {
        $groups = [];

        foreach ($annotations as $index => $annotation) {
            // Everything that stays as it is gets a key of its own, so a
            // single pass keeps the date order the query established.
            $key = self::isCollapsible($annotation)
                ? sprintf('%s/%s', $annotation->date->format('Y-m-d'), $annotation->category->value)
                : sprintf('single/%d', $index);
            $groups[$key][] = $annotation;
        }

        // One title query for the whole render, not one per group. The
        // dashboard widgets build synchronously (which is why
        // SiteMetricsOverviewBuilder already turns benchmarks off), and a
        // 90 day period with derived content markers has up to 90 groups.
        $titlesByUid = $this->pageUidRepository->titlesFor($this->collapsingPageUids($groups));
        $result = [];

        foreach ($groups as $group) {
            $collapsed = $this->collapse($group, $titlesByUid);

            if (null === $collapsed) {
                array_push($result, ...$group);

                continue;
            }

            $result[] = $collapsed;
        }

        return $result;
    }

    /**
     * Every page named by a group that will actually collapse. A group of one
     * is left as it is and its title is never asked for.
     *
     * @param array<string, non-empty-list<Annotation>> $groups
     *
     * @return list<int>
     */
    private function collapsingPageUids(array $groups): array
    {
        $pageUids = [];

        foreach ($groups as $group) {
            $ofGroup = self::distinctPageUids($group);

            if (count($ofGroup) > 1) {
                array_push($pageUids, ...$ofGroup);
            }
        }

        return array_values(array_unique($pageUids));
    }

    /**
     * @param non-empty-list<Annotation> $group
     *
     * @return list<int>
     */
    private static function distinctPageUids(array $group): array
    {
        return array_values(array_unique(array_map(static fn (Annotation $annotation): int => $annotation->pageUid, $group)));
    }

    /**
     * Null for a group that stands for a single page after all, which is
     * left alone: its own text ("Page updated") says more than a summary of
     * one ever could.
     *
     * @param non-empty-list<Annotation> $group
     * @param array<int, string>         $titlesByUid
     */
    private function collapse(array $group, array $titlesByUid): ?Annotation
    {
        $pageUids = self::distinctPageUids($group);

        if (count($pageUids) < 2) {
            return null;
        }

        $first = $group[0];

        return new Annotation(
            sprintf('collapsed/%s/%s', $first->category->value, $first->date->format('Ymd')),
            $first->date,
            $this->summarize($first->category, $pageUids, $titlesByUid),
            $first->category,
            null,
            // Site-wide on purpose: the summary is a statement about the
            // site's day, no longer about any one of the pages it counted.
            0,
            false,
            count($pageUids),
        );
    }

    /**
     * Names the first few pages instead of only counting them: "14 pages
     * updated" alone leaves a reader unable to tell the homepage from
     * fourteen archive pages, which is most of what the markers were there
     * to say.
     *
     * English like the derived texts it summarizes (see
     * Typo3AnnotationProvider::describe()) - those are stored untranslated,
     * and a localized summary over untranslated detail would read
     * half-finished.
     *
     * @param non-empty-list<int> $pageUids
     * @param array<int, string>  $titlesByUid every title the collapsing groups need, looked up once for the whole render
     */
    private function summarize(AnnotationCategory $category, array $pageUids, array $titlesByUid): string
    {
        $count = count($pageUids);
        $headline = match ($category) {
            AnnotationCategory::Content => sprintf('%d pages updated', $count),
            AnnotationCategory::Seo => sprintf('Redirects created for %d pages', $count),
            // Every other kind is already named beside the marker, in the
            // chart tooltip and in the widget's own category column, so the
            // summary does not repeat it in words of its own.
            default => sprintf('%d pages', $count),
        };

        $titles = [];

        foreach ($pageUids as $pageUid) {
            if (isset($titlesByUid[$pageUid])) {
                $titles[] = $titlesByUid[$pageUid];
            }
        }
        // Alphabetical because nothing here ranks the pages against each
        // other, and an arbitrary order would still have to be stable.
        sort($titles);
        $named = array_slice($titles, 0, self::COLLAPSE_NAMED_PAGES);

        if ([] === $named) {
            return $headline;
        }

        // Counts the pages it did not name, not the titles it did not find:
        // a page deleted since the marker was derived still happened.
        $remaining = $count - count($named);

        // Parenthesized rather than appended after a colon: the chart's
        // tooltip already puts the kind in front of the text with one ("SEO
        // change: ..."), and a second colon in the same line reads as a
        // second sentence starting.
        return sprintf(
            '%s (%s%s)',
            $headline,
            implode(', ', $named),
            $remaining > 0 ? sprintf(' and %d more', $remaining) : '',
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
