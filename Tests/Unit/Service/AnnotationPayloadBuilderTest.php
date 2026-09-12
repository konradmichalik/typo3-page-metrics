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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\Annotation;
use KonradMichalik\Typo3PageMetrics\Service\AnnotationPayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * AnnotationPayloadBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AnnotationPayloadBuilderTest extends TestCase
{
    public function testSendsDatesAsStringsAndTheCategoryAsItsBareValue(): void
    {
        // What sparkline.js compares a day against. Handing over the objects
        // themselves would encode a DateTimeImmutable as a three-field
        // object and the enum as a name/value pair, and no day would ever
        // match a marker again.
        $payload = (new AnnotationPayloadBuilder())->build([
            new Annotation(
                '1',
                new DateTimeImmutable('2026-08-02'),
                'Spring campaign',
                AnnotationCategory::Campaign,
                new DateTimeImmutable('2026-08-20'),
            ),
        ]);

        self::assertSame([[
            'date' => '2026-08-02',
            'dateUntil' => '2026-08-20',
            'category' => 'campaign',
            'text' => 'Spring campaign',
        ]], $payload);
    }

    public function testASingleDayMarkerHasNoEndDate(): void
    {
        $payload = (new AnnotationPayloadBuilder())->build([
            new Annotation('1', new DateTimeImmutable('2026-08-02'), 'Release 2.0'),
        ]);

        self::assertNull($payload[0]['dateUntil']);
        self::assertSame('release', $payload[0]['category']);
    }

    public function testAnEmptyListStaysEmpty(): void
    {
        self::assertSame([], (new AnnotationPayloadBuilder())->build([]));
    }
}
