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

namespace KonradMichalik\Typo3PageMetrics\Service;

use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\BotTaxonomy;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrafficChannel;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricDefinition;
use TYPO3\CMS\Core\Localization\LanguageService;

use function array_fill_keys;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_sum;
use function arsort;
use function count;
use function implode;
use function sprintf;
use function strlen;
use function substr;

/**
 * TileBreakdownBuilder.
 *
 * The parts a tile's popover lists under "Made up of", in the two shapes
 * that exist: where the traffic came from, and which named bot made it.
 *
 * Both shapes share one rule, which is the reason they share a class. A
 * source attributes what it can attribute, so the named parts do not add up
 * to the headline on their own. The remainder is carried as a part of its
 * own rather than left implicit: "Made up of" is a claim, and a list of 950
 * under a headline of 1,000 reads as a rendering fault instead of as traffic
 * the source could not place. Naming it keeps the claim true and says the
 * honest thing at the same time.
 *
 * Lives beside PageMetricsViewDataBuilder rather than inside it because that
 * class assembles tiles and this one answers a single question about one of
 * them - and because the builder was within fifty lines of this repository's
 * file-size ceiling before the second shape existed.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TileBreakdownBuilder
{
    private const LL = 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:';

    /**
     * How many agents a bucket lists by name before the rest become one
     * part. A crawler bucket can carry every name BotTaxonomy knows, and a
     * popover is read at a glance rather than scrolled: the strongest few
     * answer "who is coming", the long tail only lengthens the list.
     */
    private const AGENT_LIMIT = 5;

    public function __construct(
        private MetricValueFormatter $valueFormatter,
    ) {}

    /**
     * The tile's number split by where it came from.
     *
     * Read from the dimension column of the very metric the tile shows, so
     * the parts are parts of the number above them and not of a neighbouring
     * one. Empty, and therefore invisible, wherever the provider wrote no
     * split: an analytics source without referrer data must leave the
     * popover as it found it.
     *
     * @param array<string, int> $channelSums dimension => total, already fetched for every tile at once
     *
     * @return list<array{label: string, formattedValue: string}>
     */
    public function byChannel(MetricDefinition $definition, int $value, array $channelSums): array
    {
        // array_merge keeps the left operand's key order and the right
        // operand's values, so the nulls below are purely positional: a
        // known channel takes the place TrafficChannel's case order gave it,
        // one this version has never heard of lands after them under its own
        // name, and a channel with no rows is filtered back out. Shown rather
        // than dropped, because a split that silently loses traffic is worse
        // than one carrying a label nobody recognises. Reading the split by
        // prefix rather than by the declared cases is what makes that
        // possible at all.
        $positions = array_fill_keys(array_map(static fn (TrafficChannel $channel): string => $channel->dimension(), TrafficChannel::cases()), null);
        $ordered = array_filter(array_merge($positions, $channelSums), static fn (?int $channelValue): bool => null !== $channelValue);

        $parts = [];

        foreach ($ordered as $dimension => $channelValue) {
            $channel = TrafficChannel::fromDimension($dimension);
            $parts[] = [
                'label' => $channel?->label() ?? substr($dimension, strlen(TrafficChannel::DIMENSION_PREFIX)),
                'formattedValue' => $this->valueFormatter->format($channelValue, $definition->unit),
            ];
        }

        return $this->withUnattributed($parts, $definition, $value, array_sum($ordered));
    }

    /**
     * The tile's number split by which named bot made the requests.
     *
     * Sorted here rather than trusted from the caller: the cut below decides
     * which agents a reader gets to see, and a list that arrived in some
     * other order would silently cut the wrong ones.
     *
     * A part carries a hint wherever there is something to say about it, and
     * the key is simply absent where there is not: BotTaxonomy's description
     * map is curated by hand and explicitly returns null for an agent nobody
     * has written a line for yet. An empty tooltip on hover is worse than no
     * tooltip at all.
     *
     * @param array<string, int> $agentSums agent => hits for this tile's bucket, already fetched for both bot tiles at once
     *
     * @return list<array{label: string, formattedValue: string, hint?: string}>
     */
    public function byAgent(MetricDefinition $definition, int $value, array $agentSums): array
    {
        if ([] === $agentSums) {
            return [];
        }

        arsort($agentSums);

        $parts = [];

        foreach (array_slice($agentSums, 0, self::AGENT_LIMIT, true) as $agent => $hits) {
            $part = [
                // The agent as BotTaxonomy matched it, which is the name the
                // vendor publishes and the one a reader can look up. Not
                // translated: a crawler is called GPTBot in every language.
                'label' => $agent,
                'formattedValue' => $this->valueFormatter->format($hits, $definition->unit),
            ];

            // What the thing actually is, from the same class that named it.
            // "GPTBot" tells a reader who has met it before what they already
            // know and everyone else nothing at all.
            $description = BotTaxonomy::describe($agent);

            if (null !== $description) {
                $part['hint'] = $description;
            }

            $parts[] = $part;
        }

        $rest = array_slice($agentSums, self::AGENT_LIMIT, null, true);

        if ([] !== $rest) {
            // Counted, not just summed: "Others (3)" says the tail is short
            // and the five above it are the story, where a bare "Others"
            // could stand for three agents or for thirty. Which three is the
            // only follow-up question it raises, and the hint answers it
            // without spending a row per name.
            $parts[] = [
                'label' => sprintf($this->getLanguageService()->sL(self::LL.'popover.breakdown.otherAgents'), count($rest)),
                'formattedValue' => $this->valueFormatter->format(array_sum($rest), $definition->unit),
                'hint' => implode(', ', array_keys($rest)),
            ];
        }

        return $this->withUnattributed($parts, $definition, $value, array_sum($agentSums));
    }

    /**
     * Last, and only where there is one. A negative remainder is possible in
     * principle, where a source's segmented totals exceed its own
     * unsegmented one, and printing "-12 unattributed" would be a claim
     * about arithmetic nobody can act on; the parts are then simply shown as
     * they came.
     *
     * For the agent split the remainder has a second, ordinary cause: a run
     * that died mid-read commits agent detail for the days it finished while
     * the bucket metrics of that run are never written, and the next run
     * writes those metrics without revisiting the agent rows. The two are
     * kept deliberately out of step (see BotAgentDetailCollector), so the
     * gap is a fact about the import, not a defect.
     *
     * @param list<array{label: string, formattedValue: string, hint?: string}> $parts
     *
     * @return list<array{label: string, formattedValue: string, hint?: string}>
     */
    private function withUnattributed(array $parts, MetricDefinition $definition, int $value, int $attributed): array
    {
        $unattributed = $value - $attributed;

        if ([] === $parts || $unattributed <= 0) {
            return $parts;
        }

        $parts[] = [
            'label' => $this->getLanguageService()->sL(self::LL.'popover.breakdown.unattributed'),
            'formattedValue' => $this->valueFormatter->format($unattributed, $definition->unit),
        ];

        return $parts;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
