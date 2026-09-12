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

namespace KonradMichalik\Typo3PageMetrics\UserFunction;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;

/**
 * AnnotationCategoryItemsProcFunc.
 *
 * Fills the "Category" select on a manual annotation from the enum rather
 * than from a list repeated in the TCA, so a category cannot exist in one
 * place and not the other, and so the icon shown beside each option is the
 * same one the chart draws the marker with.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AnnotationCategoryItemsProcFunc
{
    /**
     * @param array<string, mixed> $fieldInformation
     */
    public function populateCategories(array &$fieldInformation): void
    {
        foreach (AnnotationCategory::cases() as $category) {
            $fieldInformation['items'][] = [
                'label' => $category->label(),
                'value' => $category->value,
                'icon' => $category->icon(),
            ];
        }
    }
}
