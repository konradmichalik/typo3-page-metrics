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

namespace KonradMichalik\Typo3PageMetrics\Command;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Configuration\{PageMetricsSiteConfiguration, SiteConfigurationReader};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, RecordReference, ResolvedUrl, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Import\RecordAggregate;
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ImportStateRepository, RecordDailyMetricsRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Provider\{AnnotationProviderInterface, MetricProviderInterface};
use KonradMichalik\Typo3PageMetrics\Resolver\Resolver;
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

use function count;
use function sprintf;

/**
 * ImportCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ImportCommand extends Command
{
    private const UNRESOLVED_CAP_PER_DAY = 100;
    private const VIA_REDIRECT_DIMENSION = 'via_redirect';

    private const VIA_ROUTE_ENHANCER_DIMENSION = 'via_route_enhancer';

    /**
     * @param iterable<MetricProviderInterface>     $providers
     * @param iterable<AnnotationProviderInterface> $annotationProviders
     */
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SiteConfigurationReader $siteConfigurationReader,
        private readonly Resolver $resolver,
        private readonly DailyMetricsRepository $dailyMetricsRepository,
        private readonly UnresolvedUrlRepository $unresolvedUrlRepository,
        private readonly ImportStateRepository $importStateRepository,
        private readonly AnnotationRepository $annotationRepository,
        private readonly RecordDailyMetricsRepository $recordDailyMetricsRepository,
        private readonly BotAgentDetailCollector $botAgentDetailCollector,
        private readonly ConnectionPool $connectionPool,
        private readonly iterable $providers,
        private readonly iterable $annotationProviders,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Import page metrics from configured providers.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Re-import the full retention window, ignoring stored import state.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $hasFailures = false;

        foreach ($this->siteFinder->getAllSites() as $site) {
            $configuration = $this->siteConfigurationReader->read($site);

            if (!$configuration->enabled) {
                continue;
            }

            // Resolver is a shared, long-lived DI service that caches
            // resolved URLs per instance. Reset once per site so a URL that
            // resolves differently under one site's configuration (e.g.
            // dropQueryString) never leaks a stale cache entry into the next
            // site processed within the same command invocation. The bot
            // agent detail collector is reset for the same reason: it
            // accumulates per provider run and is flushed under one site's
            // identifier, so anything left over from the previous site must
            // never be carried into this one's flush().
            $this->resolver->reset();
            $this->botAgentDetailCollector->reset();

            foreach ($this->providers as $provider) {
                if (!$provider->supports($site)) {
                    continue;
                }

                try {
                    $this->importOneProviderForSite($site, $provider, $configuration, $force, $output);
                } catch (Throwable $exception) {
                    // One site/provider failing (e.g. an unreachable Matomo
                    // instance) must never abort the whole run: report it and
                    // keep processing the remaining sites/providers.
                    //
                    // Whatever the failed provider still had buffered when it
                    // threw belongs to a day it never finished reading, so it
                    // will never be flushed legitimately - drop it, so the
                    // next provider's flush() does not write it out under its
                    // own identifier.
                    //
                    // The finished days drained before the failure do stay
                    // written, and that is not the symmetry it looks like: a
                    // provider that throws inside fetch() has committed no
                    // fact rows at all, because the write phase begins only
                    // once collectMetrics() has returned. So such a run can
                    // leave per-crawler detail for days whose own bot metrics
                    // were never written. Accepted knowingly, because the
                    // alternative is buffering the whole retention window
                    // again, which measured 1.9 GB: the next successful run
                    // writes the facts those rows belong to, and the agent
                    // rows are diagnostic rather than a figure anything sums.
                    $this->botAgentDetailCollector->reset();
                    $hasFailures = true;
                    $output->writeln(sprintf(
                        '<error>Failed to import provider "%s" for site "%s": %s</error>',
                        $provider->getIdentifier(),
                        $site->getIdentifier(),
                        $exception->getMessage(),
                    ));
                }
            }

            $hasFailures = $this->importAnnotationsForAllProviders($site, $configuration, $output) || $hasFailures;
        }

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }

    private function importAnnotationsForAllProviders(Site $site, PageMetricsSiteConfiguration $configuration, OutputInterface $output): bool
    {
        $hasFailures = false;

        foreach ($this->annotationProviders as $annotationProvider) {
            if (!$annotationProvider->supports($site)) {
                continue;
            }

            try {
                $this->importAnnotationsForSite($site, $annotationProvider, $configuration, $output);
            } catch (Throwable $exception) {
                $hasFailures = true;
                $output->writeln(sprintf(
                    '<error>Failed to import annotations for site "%s": %s</error>',
                    $site->getIdentifier(),
                    $exception->getMessage(),
                ));
            }
        }

        return $hasFailures;
    }

    /**
     * Unlike importOneProviderForSite(), always re-fetches the full retention
     * window rather than tracking incremental import state: annotations are
     * one cheap API call regardless of range length, and upsert() is
     * idempotent on Matomo's own annotation id, so re-fetching the same
     * range on every run is simpler than tracking a separate cursor for it.
     */
    private function importAnnotationsForSite(Site $site, AnnotationProviderInterface $annotationProvider, PageMetricsSiteConfiguration $configuration, OutputInterface $output): void
    {
        $today = new DateTimeImmutable('today');
        $range = new DateRange($today->modify(sprintf('-%d days', $configuration->retentionDays)), $today);

        $count = 0;
        foreach ($annotationProvider->fetch($site, $range) as $annotation) {
            $this->annotationRepository->upsert($site->getIdentifier(), $annotationProvider->getIdentifier(), $annotation);
            ++$count;
        }

        $output->writeln(sprintf('Imported %d annotation(s) for site "%s".', $count, $site->getIdentifier()));
    }

    private function importOneProviderForSite(Site $site, MetricProviderInterface $provider, PageMetricsSiteConfiguration $configuration, bool $force, OutputInterface $output): void
    {
        $identifier = $provider->getIdentifier();
        $today = new DateTimeImmutable('today');
        $lastImported = $force ? null : $this->importStateRepository->getLastImportedDate($identifier, $site->getIdentifier());
        $from = null !== $lastImported ? $lastImported->modify('+1 day') : $today->modify(sprintf('-%d days', $configuration->retentionDays));

        if ($from > $today) {
            return;
        }

        $range = new DateRange($from, $today);

        // Aggregated in memory, keyed by date|page|language|metric|dimension,
        // before any upsert() call: two distinct source URLs (e.g. Matomo's
        // own URL normalization differing from ours, or one direct hit plus
        // one redirect both landing on the same page/day) must sum into one
        // total rather than the second upsert() silently overwriting the
        // first - upsert() is a set operation by design (see its own
        // docblock), so summing has to happen before it, not in it.
        [$aggregated, $earliestWithData, $records] = $this->collectMetrics($site, $provider, $configuration, $range);

        // Null when the provider returned nothing at all for the range, in
        // which case this run establishes no window: it cannot tell an empty
        // history apart from an empty answer, and an unknown window masks
        // nothing rather than blanking a whole chart.
        $covered = null !== $earliestWithData ? new DateRange($earliestWithData, $today) : null;

        $connection = $this->connectionPool->getConnectionForTable('tx_typo3pagemetrics_daily');

        // One transaction per day rather than one for the whole write phase.
        // A first import asks for retentionDays (400 by default) and every
        // upsert() is a SELECT plus an INSERT or UPDATE, so a single
        // transaction around the run holds tens of thousands of round trips
        // open: innodb_lock_wait_timeout territory, a very large undo log,
        // and concurrent backend requests blocked on the same table for the
        // duration. A day is the right batch unit rather than "every N rows"
        // because its size is bounded by pages x metrics x dimensions instead
        // of by the length of the range, and because a day is also what every
        // reader queries, so no reader ever sees half of one.
        //
        // What the transaction is here for is the ordering, not atomicity of
        // the whole run: within each day the rows and the state move
        // together, so the state can never claim a day whose rows are not
        // there. Days are written oldest first, so a run that dies halfway
        // leaves a contiguous imported prefix that the state describes
        // exactly, and the next run resumes at its end. Re-importing is
        // harmless anyway because upsert() replaces rather than adds.
        //
        // The state has to move per day and not only at the end. The coverage
        // window is what ImportCoverage::mask() uses to decide which days may
        // be drawn as a number, and it treats "no window" as "withhold
        // nothing": a first import that died after writing its oldest days
        // would otherwise leave fact rows with no window at all, and every
        // sparkline would draw the days nobody fetched as a genuine zero,
        // which is the exact reading the coverage window exists to prevent.
        $yesterday = $today->modify('-1 day');

        // Records are split by the same day boundary and written inside the
        // same transaction as that day's page rows, not after all of them.
        // The cursor moves with each day, so a record loop running afterwards
        // would have its days already marked imported: a crash between the
        // two would leave those records permanently missing, because the next
        // run resumes past them and only --force would go back.
        $recordsByDay = $records->byDay();

        foreach ($this->groupByDay($aggregated) as $rowsForDay) {
            $day = $rowsForDay[0]['date']->format('Y-m-d');
            $recordsForDay = $recordsByDay[$day] ?? [];

            $connection->transactional(function () use ($rowsForDay, $recordsForDay, $identifier, $site, $earliestWithData, $yesterday, $lastImported): void {
                $this->commitDay($rowsForDay, $identifier, $site, $earliestWithData, $yesterday, $lastImported);
                $this->commitRecords($recordsForDay, $identifier);
            });
        }

        // The tail: whatever collectMetrics() could not yet call finished,
        // which is the newest day the provider emitted plus anything a
        // provider that emits out of order left behind.
        //
        // Deliberately not wrapped in a transaction: flush() resolves every
        // URL it collected before writing it (slug lookups, redirect hops),
        // which is exactly the work that must not sit inside an open one. It
        // needs no rollback either, for the same reason the days above do
        // not: its upserts replace, and the cursor has not moved yet.
        $this->botAgentDetailCollector->flush($site->getIdentifier(), $configuration->dropQueryString, $configuration->keepQueryParams);

        // Last, once every aggregate above is committed. Mark yesterday, not
        // today, as the last complete imported day: a scheduled run may only
        // capture part of today's traffic, so today must always be revisited
        // (and safely re-upserted) by the next run until it is genuinely a
        // fully elapsed prior day.
        //
        // $covered, by contrast, is which days this site has an answer
        // for at all (see ImportCoverage). The two are different
        // questions: one is "where does the next run resume", the other
        // is "which days may be drawn as a number".
        $this->importStateRepository->markImported($identifier, $site->getIdentifier(), $yesterday, $covered, null === $lastImported);

        // The count, not just the range. A provider that reads its source
        // and attributes none of it (an access log whose %v vhost token
        // names a host the site configuration does not list, say) otherwise
        // reports the same sentence as a successful run, and the only
        // difference is rows nobody looks at.
        $output->writeln(sprintf('Imported %d fact(s) for "%s" on site "%s" from %s to %s.', count($aggregated), $identifier, $site->getIdentifier(), $from->format('Y-m-d'), $today->format('Y-m-d')));
    }

    /**
     * Fetches one provider's range and folds it into the per-fact aggregate,
     * recording unresolved URLs as it goes.
     *
     * The second return value is the earliest day the provider actually
     * returned something for, which is not the same as the earliest day
     * asked about: retentionDays defaults to 400, so a first run requests
     * well over a year of history on a site that may be three weeks old.
     * Recording the asked-for range as the covered window would claim an
     * answer for every day before the site existed and draw them as a flat
     * zero, the exact false collapse ImportCoverage exists to prevent. A
     * contiguous run of empty days before a provider's first data point is
     * far more often "there was nothing to track yet" than "nobody visited".
     *
     * The third is the same aggregate for the records a route enhancer
     * placed below a page. Kept apart rather than folded in: a record is
     * identified by its table and uid, not by a page, and the same hit
     * legitimately counts twice, once for the page (which means "this page
     * and what it renders below it") and once for the record.
     *
     * @return array{0: array<string, array{pageUid: int, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}>, 1: ?DateTimeImmutable, 2: RecordAggregate}
     */
    private function collectMetrics(Site $site, MetricProviderInterface $provider, PageMetricsSiteConfiguration $configuration, DateRange $range): array
    {
        $identifier = $provider->getIdentifier();
        $aggregated = [];
        $records = new RecordAggregate();
        $earliestWithData = null;
        $openDay = null;

        foreach ($provider->fetch($site, $range) as $urlMetric) {
            if (null === $earliestWithData || $urlMetric->date < $earliestWithData) {
                $earliestWithData = $urlMetric->date;
            }

            // The agent detail a provider hands the collector is bounded by
            // the run, not by the day, and an entry costs about a kilobyte:
            // a 400-day first import of a heavily crawled site reaches two
            // million of them and dies of memory exhaustion, which is not a
            // Throwable, so the catch below cannot contain it and no import
            // state is written. Draining here, as the day the provider emits
            // moves on, bounds it at the days still being read.
            //
            // Outside every transaction on purpose, like the final flush
            // below: flushDaysBefore() resolves each URL it writes (slug
            // lookups, redirect hops), which is exactly the work that must
            // not sit inside an open one. Nothing here needs a rollback
            // either - the writes replace rather than add, and the cursor
            // has not moved at all yet.
            //
            // Only ever forward, so a provider that emits days out of order
            // can never have a day written and then added to: the second,
            // smaller date leaves the boundary where it was, and everything
            // still buffered goes out in the final flush.
            if (null === $openDay || $urlMetric->date > $openDay) {
                $openDay = $urlMetric->date;
                $this->botAgentDetailCollector->flushDaysBefore($site->getIdentifier(), $configuration->dropQueryString, $configuration->keepQueryParams, $openDay);
            }

            $result = $this->resolver->resolve($urlMetric->url, $configuration->dropQueryString, $configuration->keepQueryParams);

            // A provider may send the same fact twice: once as the total,
            // and once more carrying a dimension that names a part of it
            // (the channel split does exactly that). The dimensionless one
            // is the only one that stands for the whole, and everything
            // below that derives a second row or a diagnostic row from a
            // fact is derived from the whole, never from a part.
            $isHeadline = null === $urlMetric->dimension || '' === $urlMetric->dimension;

            if (!$result instanceof ResolvedUrl) {
                // Written here, outside every transaction the write phase
                // opens, on purpose. These rows are diagnostic, not fact
                // data: nothing draws a number from them, they only answer
                // "which URLs did this run fail to place". A run whose write
                // phase then dies is exactly the run whose trail is worth
                // keeping, and record() is idempotent on
                // (provider, date, url_hash, metric), so the next run
                // replaces the row rather than duplicating it and the cap
                // still holds. That same idempotency is why a dimensioned
                // fact is not recorded at all: it shares all four key
                // columns with its headline and would replace the total
                // already written with a fraction of it.
                if ($isHeadline) {
                    $this->unresolvedUrlRepository->record($identifier, $urlMetric->date, $urlMetric->url, $urlMetric->metric, $result->reason, $urlMetric->value, self::UNRESOLVED_CAP_PER_DAY);
                }

                continue;
            }

            $aggregated = $this->accumulate($aggregated, $result->pageUid, $result->languageUid, $urlMetric->date, $urlMetric->metric, $urlMetric->dimension ?? '', $urlMetric->value);

            $aggregated = $this->accumulateShares($aggregated, $result, $urlMetric, $isHeadline);

            if (null !== $result->record) {
                $records->add($result->record, $result->languageUid, $urlMetric->date, $urlMetric->metric, $urlMetric->dimension ?? '', $urlMetric->value);
            }
        }

        return [$aggregated, $earliestWithData, $records];
    }

    /**
     * The shares of a headline figure that a flag on the resolution carries:
     * how much arrived through a redirect, and how much arrived on a record
     * below the page rather than on the page itself.
     *
     * Derived from the headline row alone. The aggregate's key carries the
     * dimension, which is what keeps a part out of its own total, and taking
     * a share from the parts as well would add the same visits a second time
     * and let it pass 100%.
     *
     * via_route_enhancer is written from the first import that sees it even
     * though nothing displays it yet (#85), because a dimension is not
     * backfillable: waiting for the display would leave the display able to
     * speak only for traffic imported after it landed.
     *
     * @param array<string, array{pageUid: int, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}> $aggregated
     *
     * @return array<string, array{pageUid: int, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}>
     */
    private function accumulateShares(array $aggregated, ResolvedUrl $result, UrlMetric $urlMetric, bool $isHeadline): array
    {
        if (!$isHeadline) {
            return $aggregated;
        }

        if ($result->viaRedirect) {
            $aggregated = $this->accumulate($aggregated, $result->pageUid, $result->languageUid, $urlMetric->date, $urlMetric->metric, self::VIA_REDIRECT_DIMENSION, $urlMetric->value);
        }

        if ($result->viaRouteEnhancer) {
            $aggregated = $this->accumulate($aggregated, $result->pageUid, $result->languageUid, $urlMetric->date, $urlMetric->metric, self::VIA_ROUTE_ENHANCER_DIMENSION, $urlMetric->value);
        }

        return $aggregated;
    }

    /**
     * @param list<array{record: RecordReference, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}> $rows
     */
    private function commitRecords(array $rows, string $identifier): void
    {
        foreach ($rows as $row) {
            $this->recordDailyMetricsRepository->upsert($row['record'], $row['languageUid'], $identifier, $row['date'], $row['metric'], $row['value'], $row['dimension']);
        }
    }

    /**
     * Splits the run's aggregate into one batch per day, oldest day first,
     * which is the unit importOneProviderForSite() commits in.
     *
     * @param array<string, array{pageUid: int, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}> $aggregated
     *
     * @return list<list<array{pageUid: int, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}>>
     */
    private function groupByDay(array $aggregated): array
    {
        $byDay = [];

        foreach ($aggregated as $row) {
            $byDay[$row['date']->format('Y-m-d')][] = $row;
        }

        ksort($byDay);

        return array_values($byDay);
    }

    /**
     * One day's rows plus the state that describes them, inside one
     * transaction so the state can never claim a day whose rows are not
     * there.
     *
     * @param list<array{pageUid: int, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}> $rowsForDay
     */
    private function commitDay(array $rowsForDay, string $identifier, Site $site, ?DateTimeImmutable $earliestWithData, DateTimeImmutable $yesterday, ?DateTimeImmutable $lastImported): void
    {
        foreach ($rowsForDay as $row) {
            $this->dailyMetricsRepository->upsert($row['pageUid'], $row['languageUid'], $identifier, $row['date'], $row['metric'], $row['value'], $row['dimension']);
        }

        if (null === $earliestWithData) {
            return;
        }

        // Never past yesterday, for the same reason the final call stops
        // there: today is still being lived through, so it must stay
        // re-fetchable however much of it is already written. A day absent
        // from the aggregate needs no row and is still imported, the provider
        // simply had nothing for it, which is why the day just committed
        // stands for the whole range up to it.
        $day = $rowsForDay[0]['date'];
        $cursor = $day < $yesterday ? $day : $yesterday;

        $this->importStateRepository->markImported($identifier, $site->getIdentifier(), $this->neverBackwards($cursor, $identifier, $site), new DateRange($earliestWithData, $day), null === $lastImported);
    }

    /**
     * Under --force the range starts a whole retention window in the past, so
     * the oldest day commits first and would otherwise park the cursor 400
     * days back; a run that then aborts leaves every following scheduled run
     * re-fetching the entire window from the provider's API until one
     * completes. The stored cursor is read here rather than reused from the
     * caller's, which --force nulls.
     */
    private function neverBackwards(DateTimeImmutable $cursor, string $identifier, Site $site): DateTimeImmutable
    {
        $stored = $this->importStateRepository->getLastImportedDate($identifier, $site->getIdentifier());

        return null !== $stored && $stored > $cursor ? $stored : $cursor;
    }

    /**
     * @param array<string, array{pageUid: int, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}> $aggregated
     *
     * @return array<string, array{pageUid: int, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}>
     */
    private function accumulate(array $aggregated, int $pageUid, int $languageUid, DateTimeImmutable $date, string $metric, string $dimension, int $value): array
    {
        $key = implode('|', [$date->format('Y-m-d'), $pageUid, $languageUid, $metric, $dimension]);

        if (isset($aggregated[$key])) {
            $aggregated[$key]['value'] += $value;

            return $aggregated;
        }

        $aggregated[$key] = [
            'pageUid' => $pageUid,
            'languageUid' => $languageUid,
            'date' => $date,
            'metric' => $metric,
            'dimension' => $dimension,
            'value' => $value,
        ];

        return $aggregated;
    }
}
