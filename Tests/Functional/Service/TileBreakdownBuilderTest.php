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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Service;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricGroup, MetricPolarity, MetricUnit, TrafficChannel};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricDefinition;
use KonradMichalik\Typo3PageMetrics\Service\TileBreakdownBuilder;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_column;

/**
 * TileBreakdownBuilderTest.
 *
 * Functional rather than unit, because both remainder labels come out of the
 * XLF: a key that stops resolving would blank the one row in the list that
 * exists to keep the "Made up of" claim true.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TileBreakdownBuilderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private TileBreakdownBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Controller/Ajax/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $this->subject = $this->get(TileBreakdownBuilder::class);
    }

    public function testByChannelListsTheChannelsInTheEnumsOwnOrder(): void
    {
        $parts = $this->subject->byChannel($this->definition(), 100, [
            TrafficChannel::Social->dimension() => 20,
            TrafficChannel::Direct->dimension() => 50,
            TrafficChannel::Search->dimension() => 30,
        ]);

        self::assertSame([
            ['label' => 'Direct', 'formattedValue' => '50'],
            ['label' => 'Search', 'formattedValue' => '30'],
            ['label' => 'Social', 'formattedValue' => '20'],
        ], $parts);
    }

    public function testByChannelNamesAChannelItHasNeverHeardOfAfterTheKnownOnes(): void
    {
        $parts = $this->subject->byChannel($this->definition(), 30, [
            TrafficChannel::DIMENSION_PREFIX.'newsletter' => 10,
            TrafficChannel::Direct->dimension() => 20,
        ]);

        self::assertSame([
            ['label' => 'Direct', 'formattedValue' => '20'],
            ['label' => 'newsletter', 'formattedValue' => '10'],
        ], $parts);
    }

    public function testByChannelCarriesTheRemainderAsItsOwnPart(): void
    {
        $parts = $this->subject->byChannel($this->definition(), 100, [
            TrafficChannel::Direct->dimension() => 60,
        ]);

        self::assertSame([
            ['label' => 'Direct', 'formattedValue' => '60'],
            ['label' => 'Unattributed', 'formattedValue' => '40'],
        ], $parts);
    }

    public function testByChannelLeavesANegativeRemainderOut(): void
    {
        $parts = $this->subject->byChannel($this->definition(), 50, [
            TrafficChannel::Direct->dimension() => 60,
        ]);

        self::assertSame([['label' => 'Direct', 'formattedValue' => '60']], $parts);
    }

    public function testByChannelIsEmptyWithoutASplit(): void
    {
        self::assertSame([], $this->subject->byChannel($this->definition(), 100, []));
    }

    public function testByAgentListsTheAgentsStrongestFirstWithWhatEachOneIs(): void
    {
        $parts = $this->subject->byAgent($this->definition(), 30, ['ClaudeBot' => 10, 'GPTBot' => 20]);

        self::assertSame([
            [
                'label' => 'GPTBot',
                'formattedValue' => '20',
                'hint' => "OpenAI's crawler, collecting web content to train future models.",
            ],
            [
                'label' => 'ClaudeBot',
                'formattedValue' => '10',
                'hint' => "Anthropic's crawler, collecting web content to train Claude.",
            ],
        ], $parts);
    }

    public function testAnAgentWithoutACuratedDescriptionCarriesNoHint(): void
    {
        // The key is absent rather than empty: nothing to say and saying
        // nothing are the same here, and an empty tooltip on hover is worse
        // than no tooltip at all.
        self::assertSame(
            [['label' => 'SomeVendorBot', 'formattedValue' => '9']],
            $this->subject->byAgent($this->definition(), 9, ['SomeVendorBot' => 9]),
        );
    }

    public function testTheFoldedPartNamesTheAgentsItStandsFor(): void
    {
        $parts = $this->subject->byAgent($this->definition(), 100, [
            'GPTBot' => 30,
            'ClaudeBot' => 25,
            'PerplexityBot' => 20,
            'CCBot' => 15,
            'Bytespider' => 5,
            'Amazonbot' => 3,
            'YouBot' => 2,
        ]);

        // "Others (2)" says how many without saying which. The tooltip is
        // where the list belongs: it costs no row and answers the only
        // follow-up question the chip raises.
        self::assertSame(
            ['label' => 'Others (2)', 'formattedValue' => '5', 'hint' => 'Amazonbot, YouBot'],
            $parts[5],
        );
    }

    public function testByAgentCapsTheListAndFoldsTheRestIntoOnePart(): void
    {
        $parts = $this->subject->byAgent($this->definition(), 1204, [
            'GPTBot' => 612,
            'ClaudeBot' => 288,
            'PerplexityBot' => 190,
            'CCBot' => 74,
            'Bytespider' => 21,
            'Amazonbot' => 11,
            'YouBot' => 6,
            'AI2Bot' => 2,
        ]);

        self::assertSame(
            ['GPTBot', 'ClaudeBot', 'PerplexityBot', 'CCBot', 'Bytespider', 'Others (3)'],
            array_column($parts, 'label'),
        );
        self::assertSame(['612', '288', '190', '74', '21', '19'], array_column($parts, 'formattedValue'));
    }

    public function testByAgentCarriesTheGapToTheHeadlineAsItsOwnPart(): void
    {
        $parts = $this->subject->byAgent($this->definition(), 100, ['GPTBot' => 60]);

        self::assertSame(['GPTBot', 'Unattributed'], array_column($parts, 'label'));
        self::assertSame(['60', '40'], array_column($parts, 'formattedValue'));
        // The remainder is not an agent and has nothing to explain.
        self::assertArrayNotHasKey('hint', $parts[1]);
    }

    public function testByAgentIsEmptyWithoutAgentRows(): void
    {
        self::assertSame([], $this->subject->byAgent($this->definition(), 100, []));
    }

    private function definition(): MetricDefinition
    {
        return new MetricDefinition('bot.ai_crawler', 'AI crawlers', 'Requests from AI crawlers.', 'tabler-spider', MetricUnit::Count, 40, '#ae3ec9', MetricPolarity::Neutral, MetricGroup::Machine);
    }
}
