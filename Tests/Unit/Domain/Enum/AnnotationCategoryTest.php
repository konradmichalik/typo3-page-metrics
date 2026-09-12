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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\Enum;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AnnotationCategoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AnnotationCategoryTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, AnnotationCategory}>
     */
    public static function storedValueProvider(): iterable
    {
        yield 'a known value' => ['campaign', AnnotationCategory::Campaign];
        yield 'a row written before the column existed' => [null, AnnotationCategory::Release];
        yield 'an empty column' => ['', AnnotationCategory::Release];
        yield 'something hand-edited into the database' => ['deployment', AnnotationCategory::Release];
    }

    #[DataProvider('storedValueProvider')]
    public function testFallsBackToReleaseForAnythingItDidNotWriteItself(?string $stored, AnnotationCategory $expected): void
    {
        // Release is what every annotation in this extension meant before
        // categories existed, so it is the honest reading of a row that
        // predates them.
        self::assertSame($expected, AnnotationCategory::fromStorage($stored));
    }

    public function testEveryCategoryHasItsOwnIcon(): void
    {
        // A shared icon would make two kinds indistinguishable in the legend
        // and in the record editor, which is the one thing the category is
        // for.
        $icons = array_map(static fn (AnnotationCategory $category): string => $category->icon(), AnnotationCategory::cases());

        self::assertSame($icons, array_unique($icons));
        self::assertCount(7, $icons);
    }

    public function testEveryCategoryPointsAtItsOwnLabelKey(): void
    {
        foreach (AnnotationCategory::cases() as $category) {
            self::assertStringEndsWith('annotation.category.'.$category->value, $category->label());
        }
    }
}
