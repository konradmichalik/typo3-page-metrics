<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\ValueObject;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\Annotation;
use PHPUnit\Framework\TestCase;

/**
 * AnnotationTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AnnotationTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $date = new DateTimeImmutable('2026-08-01');
        $annotation = new Annotation('1', $date, 'Release 2.0');

        self::assertSame('1', $annotation->id);
        self::assertSame($date, $annotation->date);
        self::assertSame('Release 2.0', $annotation->text);
    }
}
