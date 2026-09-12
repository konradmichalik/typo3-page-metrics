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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, ResolvedUrl};
use KonradMichalik\Typo3PageMetrics\Persistence\{AnnotationRepository, DailyMetricsRepository, ImportStateRepository, UnresolvedUrlRepository};
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
                    // Whatever the failed provider already handed the
                    // collector before throwing was never flushed and never
                    // will be - drop it, so the next provider's flush() does
                    // not write it out under its own identifier.
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
            $this->annotationRepository->upsert($site->getIdentifier(), $annotationProvider->getIdentifier(), $annotation->id, $annotation->date, $annotation->text);
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
        [$aggregated, $earliestWithData] = $this->collectMetrics($site, $provider, $configuration, $range);

        // Null when the provider returned nothing at all for the range, in
        // which case this run establishes no window: it cannot tell an empty
        // history apart from an empty answer, and an unknown window masks
        // nothing rather than blanking a whole chart.
        $covered = null !== $earliestWithData ? new DateRange($earliestWithData, $today) : null;

        $connection = $this->connectionPool->getConnectionForTable('tx_typo3pagemetrics_daily');
        $connection->transactional(function () use ($aggregated, $identifier, $site, $configuration, $today, $covered, $lastImported): void {
            foreach ($aggregated as $row) {
                $this->dailyMetricsRepository->upsert($row['pageUid'], $row['languageUid'], $identifier, $row['date'], $row['metric'], $row['value'], $row['dimension']);
            }

            $this->botAgentDetailCollector->flush($site->getIdentifier(), $configuration->dropQueryString, $configuration->keepQueryParams);

            // Mark yesterday, not today, as the last complete imported day: a
            // scheduled run may only capture part of today's traffic, so today
            // must always be revisited (and safely re-upserted) by the next run
            // until it is genuinely a fully elapsed prior day.
            //
            // $covered, by contrast, is which days this site has an answer
            // for at all (see ImportCoverage). The two are different
            // questions: one is "where does the next run resume", the other
            // is "which days may be drawn as a number".
            $this->importStateRepository->markImported($identifier, $site->getIdentifier(), $today->modify('-1 day'), $covered, null === $lastImported);
        });

        $output->writeln(sprintf('Imported "%s" for site "%s" from %s to %s.', $identifier, $site->getIdentifier(), $from->format('Y-m-d'), $today->format('Y-m-d')));
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
     * @return array{0: array<string, array{pageUid: int, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}>, 1: ?DateTimeImmutable}
     */
    private function collectMetrics(Site $site, MetricProviderInterface $provider, PageMetricsSiteConfiguration $configuration, DateRange $range): array
    {
        $identifier = $provider->getIdentifier();
        $aggregated = [];
        $earliestWithData = null;

        foreach ($provider->fetch($site, $range) as $urlMetric) {
            if (null === $earliestWithData || $urlMetric->date < $earliestWithData) {
                $earliestWithData = $urlMetric->date;
            }

            $result = $this->resolver->resolve($urlMetric->url, $configuration->dropQueryString, $configuration->keepQueryParams);

            if (!$result instanceof ResolvedUrl) {
                $this->unresolvedUrlRepository->record($identifier, $urlMetric->date, $urlMetric->url, $urlMetric->metric, $result->reason, $urlMetric->value, self::UNRESOLVED_CAP_PER_DAY);

                continue;
            }

            $aggregated = $this->accumulate($aggregated, $result->pageUid, $result->languageUid, $urlMetric->date, $urlMetric->metric, $urlMetric->dimension ?? '', $urlMetric->value);

            if ($result->viaRedirect) {
                $aggregated = $this->accumulate($aggregated, $result->pageUid, $result->languageUid, $urlMetric->date, $urlMetric->metric, self::VIA_REDIRECT_DIMENSION, $urlMetric->value);
            }
        }

        return [$aggregated, $earliestWithData];
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
