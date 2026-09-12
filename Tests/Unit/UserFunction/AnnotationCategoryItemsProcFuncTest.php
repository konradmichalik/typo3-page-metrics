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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\UserFunction;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\UserFunction\AnnotationCategoryItemsProcFunc;
use PHPUnit\Framework\TestCase;

/**
 * AnnotationCategoryItemsProcFuncTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AnnotationCategoryItemsProcFuncTest extends TestCase
{
    public function testAppendsOneItemPerCategoryWithItsOwnIcon(): void
    {
        // The select is built from the enum so a category cannot exist in the
        // chart and not in the record editor, and so the icon beside an
        // option is the one the marker is drawn with.
        $fieldInformation = ['items' => []];
        (new AnnotationCategoryItemsProcFunc())->populateCategories($fieldInformation);

        self::assertSameSize(AnnotationCategory::cases(), $fieldInformation['items']);
        self::assertSame([
            'label' => AnnotationCategory::Release->label(),
            'value' => 'release',
            'icon' => AnnotationCategory::Release->icon(),
        ], $fieldInformation['items'][0]);
    }

    public function testKeepsItemsThatTcaAlreadyDefined(): void
    {
        $fieldInformation = ['items' => [['label' => '', 'value' => '']]];
        (new AnnotationCategoryItemsProcFunc())->populateCategories($fieldInformation);

        self::assertSame(['label' => '', 'value' => ''], $fieldInformation['items'][0]);
        self::assertSame('release', $fieldInformation['items'][1]['value']);
    }
}
