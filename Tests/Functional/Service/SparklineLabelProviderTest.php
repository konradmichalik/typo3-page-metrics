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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Service\SparklineLabelProvider;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SparklineLabelProviderTest.
 *
 * The point of these is the seam between the key list in the provider and the
 * trans-unit ids in the XLF. A mismatch between the two fails silently: the
 * provider drops an unresolvable key on purpose, the client falls back to its
 * English default, and the label keeps rendering in the wrong language on a
 * translated backend without anything going red.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SparklineLabelProviderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private SparklineLabelProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Controller/Ajax/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $this->subject = $this->get(SparklineLabelProvider::class);
    }

    public function testEveryDeclaredKeyResolvesToATranslation(): void
    {
        $labels = $this->subject->all();

        self::assertSame([
            'lastDay',
            'previousDay',
            'peak',
            'average',
            'viaRedirects',
            'onRecords',
            'lastDayHint',
            'previousDayHint',
            'peakHint',
            'averageHint',
            'viaRedirectsHint',
            'onRecordsHint',
            'breakdown',
            'typicalPage',
            'typicalPageHint',
            'typicalPageTopFifth',
            'typicalPageAboveMedian',
            'typicalPageAtMedian',
            'typicalPageBelowMedian',
            'typicalPageBottomFifth',
            'noData',
            'externalLink',
            'externalLinkNamed',
            'source',
            'sourceHint',
            'sliderPrevious',
            'sliderNext',
            'sliderPage',
            'chartSummary',
            'chartSummaryDates',
            'chartScaleSeparate',
            'annotationCategory.release',
            'annotationCategory.campaign',
            'annotationCategory.content',
            'annotationCategory.redesign',
            'annotationCategory.incident',
            'annotationCategory.seo',
            'annotationCategory.external',
        ], array_keys($labels));
    }

    public function testEveryAnnotationCategoryReachesTheClient(): void
    {
        // The marker tooltip names the kind of event because the colour
        // alone does not, and it can only do that for a category the server
        // actually sent a label for.
        $labels = $this->subject->all();

        foreach (AnnotationCategory::cases() as $category) {
            self::assertArrayHasKey('annotationCategory.'.$category->value, $labels);
        }
    }

    public function testTheChartSummaryKeepsItsPlaceholders(): void
    {
        // The client fills these in positionally, so losing one in a
        // translation is the difference between a spoken date range and a
        // literal "%3$s" read out to a screen reader.
        $labels = $this->subject->all();

        foreach (['%1$s', '%2$s', '%3$s', '%4$s'] as $placeholder) {
            self::assertStringContainsString($placeholder, $labels['chartSummary']);
        }

        self::assertStringContainsString('%1$s', $labels['chartSummaryDates']);
        self::assertStringContainsString('%2$s', $labels['chartSummaryDates']);
    }
}
