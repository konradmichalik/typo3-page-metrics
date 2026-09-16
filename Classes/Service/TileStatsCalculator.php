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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{MetricTile, TileStat};
use TYPO3\CMS\Core\Localization\LanguageService;

use function array_filter;
use function array_sum;
use function array_values;
use function count;
use function max;
use function round;
use function sprintf;

/**
 * TileStatsCalculator.
 *
 * The qualifying figures beside a tile's headline, for a surface that is
 * rendered on the server. sparkline.js derives the same four from the same
 * series for its popover, and this is deliberately a second implementation
 * rather than a shared one: the popover is built in the browser from a data
 * attribute, the backend module is built in PHP from the MetricTile itself,
 * and nothing can pass between them. What keeps the two honest is that the
 * rules below are pinned by TileStatsCalculatorTest, so a change on one side
 * that is not made on the other fails rather than quietly disagreeing.
 *
 * The rules, and why they differ between the four:
 *
 * Last day and previous day are positions in the period, not the two most
 * recent days that happen to carry data. The import marks a day complete
 * only once it has elapsed, so the final point is routinely still empty, and
 * filtering first would silently relabel whichever days do have numbers.
 *
 * Peak and average are statements about the data that exists, so they skip
 * the empty days: counting an uncovered day as zero drags the average down
 * and reports a peak of 0 for a period nobody imported.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TileStatsCalculator
{
    private const LL = 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:';

    public function __construct(
        private MetricValueFormatter $formatter,
    ) {}

    /**
     * @return list<TileStat>
     */
    public function forTile(MetricTile $tile): array
    {
        $values = array_values($tile->sparkline);
        $dayCount = count($values);

        $withData = array_values(array_filter($values, static fn (?int $value): bool => null !== $value));
        $peak = [] !== $withData ? max($withData) : null;
        $average = [] !== $withData ? (int) round(array_sum($withData) / count($withData)) : null;

        $stats = [
            $this->stat('lastDay', $this->format($dayCount > 0 ? $values[$dayCount - 1] : null, $tile)),
            $this->stat('previousDay', $this->format($dayCount > 1 ? $values[$dayCount - 2] : null, $tile)),
            $this->stat('peak', $this->format($peak, $tile)),
            $this->stat('average', $this->format($average, $tile)),
        ];

        if ($tile->redirectSharePercent > 0) {
            $stats[] = $this->stat('viaRedirects', sprintf('%d%%', $tile->redirectSharePercent));
        }

        // The figure itself rather than a second percentage, and absent
        // entirely where nothing arrived that way, which is almost every
        // page: a formatted "0" would be a statement about records that are
        // not there.
        if ('' !== $tile->recordFormattedValue) {
            $stats[] = $this->stat('onRecords', $tile->recordFormattedValue);
        }

        // Last, and the only one that is about the other pages of the site
        // rather than about this one over the period.
        if (null !== $tile->benchmark) {
            $stats[] = new TileStat(
                $this->translate('popover.typicalPage'),
                $this->benchmarkHint($tile),
                $tile->benchmark->formattedMedian,
            );
        }

        return $stats;
    }

    private function benchmarkHint(MetricTile $tile): string
    {
        $benchmark = $tile->benchmark;

        if (null === $benchmark) {
            return '';
        }

        $hint = sprintf($this->translate('popover.typicalPageHint'), $benchmark->pageCount);

        if (null === $benchmark->band) {
            return $hint;
        }

        // Where this page sits against that median, which is the part that
        // makes the median worth printing: "1240 against a typical 900" is
        // only a finding once you know 900 is the middle of the site.
        return sprintf('%s. %s', $hint, $this->translate('popover.typicalPage.'.$benchmark->band->value));
    }

    private function stat(string $key, ?string $value): TileStat
    {
        return new TileStat(
            $this->translate('popover.'.$key),
            $this->translate('popover.'.$key.'Hint'),
            $value,
        );
    }

    private function format(?int $value, MetricTile $tile): ?string
    {
        return null === $value ? null : $this->formatter->format($value, $tile->unit);
    }

    private function translate(string $key): string
    {
        return $this->getLanguageService()->sL(self::LL.$key);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
