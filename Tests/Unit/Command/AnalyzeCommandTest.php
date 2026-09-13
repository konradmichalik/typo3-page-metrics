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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Command;

use KonradMichalik\Typo3PageMetrics\Analysis\Guard\PathHeuristicGuard;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\CombinedLogParser;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\{AgentMatcher, BotTaxonomy};
use KonradMichalik\Typo3PageMetrics\Command\AnalyzeCommand;
use KonradMichalik\Typo3PageMetrics\Import\{LogFileReader, SourceLocator};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * AnalyzeCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(AnalyzeCommand::class)]
final class AnalyzeCommandTest extends TestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $command = new AnalyzeCommand(
            new SourceLocator(),
            new LogFileReader(),
            new CombinedLogParser(),
            new BotTaxonomy(new AgentMatcher()),
            new PathHeuristicGuard(),
        );

        $this->tester = new CommandTester($command);
    }

    #[Test]
    public function executeFailsWhenNoReadableFilesAreFound(): void
    {
        $exitCode = $this->tester->execute(['paths' => [__DIR__.'/Fixtures/does-not-exist.log']]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No readable log files found', $this->tester->getDisplay());
    }

    #[Test]
    public function executeFailsWhenNoLineInTheGivenFilesParses(): void
    {
        $exitCode = $this->tester->execute(['paths' => [__DIR__.'/Fixtures/garbage.log']]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('contained a parseable Combined Log Format line', $this->tester->getDisplay());
    }

    #[Test]
    public function executeBlamesTheDateFilterWhenEveryParsedLineFallsBeforeSince(): void
    {
        $exitCode = $this->tester->execute([
            'paths' => [__DIR__.'/Fixtures/access.log'],
            '--since' => '2026-11-01',
        ]);
        $display = $this->normalizedDisplay();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No entries on or after 2026-11-01', $display);
        self::assertStringNotContainsString('contained a parseable Combined Log Format line', $display);
    }

    #[Test]
    public function executeNamesBothCausesWhenSomeLinesDoNotParseAndTheRestAreFiltered(): void
    {
        $exitCode = $this->tester->execute([
            'paths' => [__DIR__.'/Fixtures/access.log', __DIR__.'/Fixtures/garbage.log'],
            '--since' => '2026-11-01',
        ]);
        $display = $this->normalizedDisplay();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No entries on or after 2026-11-01', $display);
        self::assertStringContainsString('2 parsed line(s) fall before that date', $display);
        self::assertStringContainsString('1 further line(s)', $display);
    }

    #[Test]
    public function executeRendersTheOverviewAndAgentTablesForAKnownBot(): void
    {
        $exitCode = $this->tester->execute(['paths' => [__DIR__.'/Fixtures/access.log']]);
        $display = $this->tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Overview', $display);
        self::assertStringContainsString('ai_crawler', $display);
        self::assertStringContainsString('GPTBot', $display);
    }

    #[Test]
    public function sinceOptionExcludesEntriesBeforeTheGivenDate(): void
    {
        $exitCode = $this->tester->execute([
            'paths' => [__DIR__.'/Fixtures/access.log'],
            '--since' => '2026-10-05',
        ]);
        $display = $this->tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('/about', $display);
        self::assertStringNotContainsString('/legacy', $display);
    }

    #[Test]
    public function sinceOptionRejectsAnInvalidCalendarDate(): void
    {
        $exitCode = $this->tester->execute([
            'paths' => [__DIR__.'/Fixtures/access.log'],
            '--since' => '2026-02-31',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--since expects YYYY-MM-DD.', $this->tester->getDisplay());
    }

    /**
     * SymfonyStyle hard-wraps an error block at the terminal width, so a
     * message long enough to span two lines cannot be asserted on verbatim.
     */
    private function normalizedDisplay(): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->tester->getDisplay());
    }
}
