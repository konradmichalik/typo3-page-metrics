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

use KonradMichalik\Typo3PageMetrics\Configuration\{PageMetricsSiteConfiguration, SiteConfigurationReader};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, ResolvedUrl};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, ImportStateRepository, UnresolvedUrlRepository};
use KonradMichalik\Typo3PageMetrics\Resolver\Resolver;
use KonradMichalik\Typo3PageMetrics\Source\MetricSourceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class ImportCommand extends Command
{
    private const UNRESOLVED_CAP_PER_DAY = 100;

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
                if ($source->supports($site)) {
                    $this->importOneSourceForSite($site, $source, $configuration, $force, $output);
                }
            }
        }

        return Command::SUCCESS;
    }

    private function importOneSourceForSite(Site $site, MetricSourceInterface $source, PageMetricsSiteConfiguration $configuration, bool $force, OutputInterface $output): void
    {
        $identifier = $source->getIdentifier();
        $today = new \DateTimeImmutable('today');
        $lastImported = $force ? null : $this->importStateRepository->getLastImportedDate($identifier, $site->getIdentifier());
        $from = null !== $lastImported ? $lastImported->modify('+1 day') : $today->modify(sprintf('-%d days', $configuration->retentionDays));

        if ($from > $today) {
            return;
        }

        $range = new DateRange($from, $today);

        foreach ($source->fetch($site, $range) as $urlMetric) {
            $result = $this->resolver->resolve($urlMetric->url, $configuration->dropQueryString, $configuration->keepQueryParams);

            if ($result instanceof ResolvedUrl) {
                $this->dailyMetricsRepository->upsert($result->pageUid, $result->languageUid, $identifier, $urlMetric->date, $urlMetric->metric, $urlMetric->value, $urlMetric->dimension);

                continue;
            }

            $this->unresolvedUrlRepository->record($identifier, $urlMetric->date, $urlMetric->url, $urlMetric->metric, $result->reason, $urlMetric->value, self::UNRESOLVED_CAP_PER_DAY);
        }

        $this->importStateRepository->markImported($identifier, $site->getIdentifier(), $today);
        $output->writeln(sprintf('Imported "%s" for site "%s" from %s to %s.', $identifier, $site->getIdentifier(), $from->format('Y-m-d'), $today->format('Y-m-d')));
    }
}
