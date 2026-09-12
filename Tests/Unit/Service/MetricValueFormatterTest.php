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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Service\MetricValueFormatter;
use PHPUnit\Framework\TestCase;

/**
 * MetricValueFormatterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MetricValueFormatterTest extends TestCase
{
    private MetricValueFormatter $subject;

    protected function setUp(): void
    {
        $this->subject = new MetricValueFormatter();
    }

    public function testACountIsItsOwnDigits(): void
    {
        self::assertSame('4653', $this->subject->format(4653, MetricUnit::Count));
    }

    public function testAPercentCarriesItsSign(): void
    {
        self::assertSame('84%', $this->subject->format(84, MetricUnit::Percent));
    }

    public function testSecondsBecomeMinutesAndSeconds(): void
    {
        self::assertSame('2:23', $this->subject->format(143, MetricUnit::Seconds));
    }

    public function testTheSecondsPartKeepsItsLeadingZero(): void
    {
        // "1:3" would read as a minute and three tenths.
        self::assertSame('1:03', $this->subject->format(63, MetricUnit::Seconds));
    }

    public function testADurationUnderAMinuteStillLeadsWithZero(): void
    {
        self::assertSame('0:03', $this->subject->format(3, MetricUnit::Seconds));
    }
}
