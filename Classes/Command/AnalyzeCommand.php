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
use KonradMichalik\Typo3PageMetrics\Analysis\Aggregate\Aggregator;
use KonradMichalik\Typo3PageMetrics\Analysis\Guard\SpoofingGuardInterface;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\LogParserInterface;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\{BotTaxonomy, Bucket};
use KonradMichalik\Typo3PageMetrics\Import\{LogFileReader, SourceLocator};
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_slice;
use function count;
use function sprintf;

/**
 * AnalyzeCommand.
 *
 * Ad-hoc log analysis without touching the database: parses, classifies
 * and aggregates the given files entirely in memory via `Analysis\*`,
 * then prints the result. This is the CLI counterpart that validates the
 * `Analysis\` package's TYPO3-free boundary (spec §4.1) — every
 * dependency below the orchestration in this command works without a
 * TYPO3 bootstrap.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class AnalyzeCommand extends Command
{
    public function __construct(
        private readonly SourceLocator $sourceLocator,
        private readonly LogFileReader $logFileReader,
        private readonly LogParserInterface $parser,
        private readonly BotTaxonomy $taxonomy,
        private readonly SpoofingGuardInterface $guard,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'One or more log files or directories to analyze')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only include entries on or after this date (YYYY-MM-DD)')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many top entries to show per table', '10')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $since = $input->getOption('since');
        if (null !== $since) {
            $sinceDate = DateTimeImmutable::createFromFormat('!Y-m-d', $since);
            if (false === $sinceDate || $sinceDate->format('Y-m-d') !== $since) {
                $io->error('--since expects YYYY-MM-DD.');

                return Command::FAILURE;
            }
        }

        $top = max(1, (int) $input->getOption('top'));

        $files = $this->sourceLocator->expand($input->getArgument('paths'));
        if ([] === $files) {
            $io->error('No readable log files found in the given paths.');

            return Command::FAILURE;
        }

        $aggregator = new Aggregator();
        $linesParsed = 0;

        foreach ($files as $file) {
            $linesParsed += $this->processFile($file, $since, $aggregator, $io);
        }

        if (0 === $linesParsed) {
            $io->error(sprintf('None of the %d file(s) contained a parseable Combined Log Format line.', count($files)));

            return Command::FAILURE;
        }

        $this->renderOverview($io, $aggregator);
        $this->renderAgentTables($io, $aggregator, $top);
        $this->renderUrlTables($io, $aggregator, $top);

        return Command::SUCCESS;
    }

    /**
     * Reads and processes a single file, skipping it with a warning if it
     * cannot be read. This is a defensive backstop for read failures that
     * happen after `SourceLocator`'s readability check (e.g. a file becomes
     * unreadable between the check and the actual read).
     *
     * @return int number of lines that counted toward the parsed-lines total
     */
    private function processFile(string $file, ?string $since, Aggregator $aggregator, SymfonyStyle $io): int
    {
        $linesParsed = 0;

        try {
            foreach ($this->logFileReader->readLines($file) as $line) {
                if ($this->processLine($line, $since, $aggregator)) {
                    ++$linesParsed;
                }
            }
        } catch (RuntimeException $e) {
            $io->warning(sprintf('Skipping %s: %s', $file, $e->getMessage()));
        }

        return $linesParsed;
    }

    /**
     * Parses, since-filters, classifies and aggregates a single log line.
     *
     * @return bool whether the line counted toward the parsed-lines total
     *              (i.e. it was a parseable line that passed the --since filter,
     *              regardless of whether it matched a known bot classification)
     */
    private function processLine(string $line, ?string $since, Aggregator $aggregator): bool
    {
        $logLine = $this->parser->parseLine($line);
        if (null === $logLine) {
            return false;
        }
        if (null !== $since && $logLine->timestamp->format('Y-m-d') < $since) {
            return false;
        }

        $classification = $this->taxonomy->classify($logLine);
        if (null === $classification) {
            return true;
        }

        $aggregator->add($logLine, $classification, $this->guard->isSuspicious($logLine));

        return true;
    }

    private function renderOverview(SymfonyStyle $io, Aggregator $aggregator): void
    {
        $totals = [];
        foreach ($aggregator->agentDailyAggregates() as $row) {
            $key = $row->bucket->value;
            $totals[$key] ??= ['hits' => 0, 'hitsOk' => 0, 'hitsForbidden' => 0, 'hitsSuspicious' => 0];
            $totals[$key]['hits'] += $row->hits;
            $totals[$key]['hitsOk'] += $row->hitsOk;
            $totals[$key]['hitsForbidden'] += $row->hitsForbidden;
            $totals[$key]['hitsSuspicious'] += $row->hitsSuspicious;
        }

        if ([] === $totals) {
            $io->warning('No known bot traffic found in the given input.');

            return;
        }

        $io->section('Overview');
        $rows = [];
        foreach ($totals as $bucket => $counts) {
            $rows[] = [$bucket, $counts['hits'], $counts['hitsOk'], $counts['hitsForbidden'], $counts['hitsSuspicious']];
        }
        $io->table(['Bucket', 'Hits', 'OK (2xx)', 'Forbidden (403)', 'Suspicious'], $rows);
    }

    private function renderAgentTables(SymfonyStyle $io, Aggregator $aggregator, int $top): void
    {
        $byBucket = [];
        foreach ($aggregator->agentDailyAggregates() as $row) {
            $bucketKey = $row->bucket->value;
            $byBucket[$bucketKey][$row->agent] ??= ['hits' => 0, 'hitsOk' => 0, 'hitsSuspicious' => 0];
            $byBucket[$bucketKey][$row->agent]['hits'] += $row->hits;
            $byBucket[$bucketKey][$row->agent]['hitsOk'] += $row->hitsOk;
            $byBucket[$bucketKey][$row->agent]['hitsSuspicious'] += $row->hitsSuspicious;
        }

        foreach ($byBucket as $bucketKey => $agents) {
            uasort($agents, static fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);
            $agents = array_slice($agents, 0, $top, true);

            $io->section(sprintf('Agents: %s', $bucketKey));
            $rows = [];
            foreach ($agents as $agent => $counts) {
                $rows[] = [$agent, $counts['hits'], $counts['hitsOk'], $counts['hitsSuspicious']];
            }
            $io->table(['Agent', 'Hits', 'OK (2xx)', 'Suspicious'], $rows);
        }
    }

    private function renderUrlTables(SymfonyStyle $io, Aggregator $aggregator, int $top): void
    {
        foreach ([Bucket::AiChatbot, Bucket::AiCrawler] as $bucket) {
            $rows = [];
            foreach ($aggregator->urlDailyAggregates() as $row) {
                if ($row->bucket !== $bucket) {
                    continue;
                }
                $key = $row->url.'|'.$row->status;
                $rows[$key] ??= ['url' => $row->url, 'status' => $row->status, 'hits' => 0];
                $rows[$key]['hits'] += $row->hits;
            }

            if ([] === $rows) {
                continue;
            }

            usort($rows, static fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);
            $rows = array_slice($rows, 0, $top);

            $io->section(sprintf('Top URLs: %s', $bucket->value));
            $io->table(['URL', 'Status', 'Hits'], array_map(
                static fn (array $r): array => [$r['url'], $r['status'], $r['hits']],
                $rows,
            ));
        }
    }
}
