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

namespace KonradMichalik\Typo3PageMetrics\Command;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Configuration\{PageMetricsSiteConfiguration, SiteConfigurationReader};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, ResolvedUrl};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, ImportStateRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Resolver\Resolver;
use KonradMichalik\Typo3PageMetrics\Source\MetricSourceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
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
     * @param iterable<MetricSourceInterface> $sources
     */
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SiteConfigurationReader $siteConfigurationReader,
        private readonly Resolver $resolver,
        private readonly DailyMetricsRepository $dailyMetricsRepository,
        private readonly UnresolvedUrlRepository $unresolvedUrlRepository,
        private readonly ImportStateRepository $importStateRepository,
        private readonly iterable $sources,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Import page metrics from configured sources.');
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
            // site processed within the same command invocation.
            $this->resolver->reset();

            foreach ($this->sources as $source) {
                if (!$source->supports($site)) {
                    continue;
                }

                try {
                    $this->importOneSourceForSite($site, $source, $configuration, $force, $output);
                } catch (Throwable $exception) {
                    // One site/source failing (e.g. an unreachable Matomo
                    // instance) must never abort the whole run: report it and
                    // keep processing the remaining sites/sources.
                    $hasFailures = true;
                    $output->writeln(sprintf(
                        '<error>Failed to import source "%s" for site "%s": %s</error>',
                        $source->getIdentifier(),
                        $site->getIdentifier(),
                        $exception->getMessage(),
                    ));
                }
            }
        }

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
    }

    private function importOneSourceForSite(Site $site, MetricSourceInterface $source, PageMetricsSiteConfiguration $configuration, bool $force, OutputInterface $output): void
    {
        $identifier = $source->getIdentifier();
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
        $aggregated = [];

        foreach ($source->fetch($site, $range) as $urlMetric) {
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

        foreach ($aggregated as $row) {
            $this->dailyMetricsRepository->upsert($row['pageUid'], $row['languageUid'], $identifier, $row['date'], $row['metric'], $row['value'], $row['dimension']);
        }

        // Mark yesterday, not today, as the last complete imported day: a
        // scheduled run may only capture part of today's traffic, so today
        // must always be revisited (and safely re-upserted) by the next run
        // until it is genuinely a fully elapsed prior day.
        $this->importStateRepository->markImported($identifier, $site->getIdentifier(), $today->modify('-1 day'));
        $output->writeln(sprintf('Imported "%s" for site "%s" from %s to %s.', $identifier, $site->getIdentifier(), $from->format('Y-m-d'), $today->format('Y-m-d')));
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
