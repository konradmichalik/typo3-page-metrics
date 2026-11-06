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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * SparklineLabelProvider.
 *
 * The strings sparkline.js renders, the tile slider's own aria-labels
 * included. They are translated here and handed to the client as one data
 * attribute rather than being written into the JavaScript, which is where
 * they used to live: hard-coded English in a file that never sees a
 * LanguageService, while every other label in the extension sits in the XLF.
 *
 * Three consumers need the same set (the page-header AJAX route and the two
 * dashboard widgets that render sparkline markup), which is why this is a
 * service rather than three copies of the same array.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SparklineLabelProvider
{
    private const LL = 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:';

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $languageService = $this->getLanguageService();

        $keys = [
            'popover.lastDay' => 'lastDay',
            'popover.previousDay' => 'previousDay',
            'popover.peak' => 'peak',
            'popover.average' => 'average',
            'popover.viaRedirects' => 'viaRedirects',
            'popover.onRecords' => 'onRecords',
            'popover.lastDayHint' => 'lastDayHint',
            'popover.previousDayHint' => 'previousDayHint',
            'popover.peakHint' => 'peakHint',
            'popover.averageHint' => 'averageHint',
            'popover.viaRedirectsHint' => 'viaRedirectsHint',
            'popover.onRecordsHint' => 'onRecordsHint',
            'popover.breakdown' => 'breakdown',
            'popover.typicalPage' => 'typicalPage',
            'popover.typicalPageHint' => 'typicalPageHint',
            'popover.typicalPage.topFifth' => 'typicalPageTopFifth',
            'popover.typicalPage.aboveMedian' => 'typicalPageAboveMedian',
            'popover.typicalPage.atMedian' => 'typicalPageAtMedian',
            'popover.typicalPage.belowMedian' => 'typicalPageBelowMedian',
            'popover.typicalPage.bottomFifth' => 'typicalPageBottomFifth',
            'popover.noData' => 'noData',
            'popover.externalLink' => 'externalLink',
            'popover.externalLinkNamed' => 'externalLinkNamed',
            'popover.source' => 'source',
            'popover.sourceHint' => 'sourceHint',
            'slider.previous' => 'sliderPrevious',
            'slider.next' => 'sliderNext',
            'slider.page' => 'sliderPage',
            'chart.summary' => 'chartSummary',
            'chart.summaryDates' => 'chartSummaryDates',
        ];

        // The marker tooltip names the kind of event, so the category
        // colour is not the only thing carrying it on a page-header tile,
        // which has no legend. Built from the enum rather than listed, so a
        // new category cannot be missing here.
        foreach (AnnotationCategory::cases() as $category) {
            $keys['annotation.category.'.$category->value] = 'annotationCategory.'.$category->value;
        }

        $labels = [];
        foreach ($keys as $id => $key) {
            $label = $languageService->sL(self::LL.$id);

            // sL() returns an empty string for a key it cannot resolve.
            // Passing that on would blank the label in the UI, while the
            // client already carries an English fallback for exactly this
            // case - so an unresolvable key is left out rather than sent as
            // an empty override.
            if ('' !== $label) {
                $labels[$key] = $label;
            }
        }

        return $labels;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
