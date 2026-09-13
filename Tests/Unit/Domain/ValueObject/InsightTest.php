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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Domain\ValueObject;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrendSentiment;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\Insight;
use PHPUnit\Framework\TestCase;

/**
 * InsightTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InsightTest extends TestCase
{
    public function testCountsBothSiteSentencesAsSiteLevel(): void
    {
        self::assertTrue($this->insight(Insight::SENTENCE_SITE_UP)->isSiteLevel());
        self::assertTrue($this->insight(Insight::SENTENCE_SITE_DOWN)->isSiteLevel());
    }

    public function testCountsBothPageSentencesAsPageLevel(): void
    {
        self::assertFalse($this->insight(Insight::SENTENCE_PAGE_RISE)->isSiteLevel());
        self::assertFalse($this->insight(Insight::SENTENCE_PAGE_FALL)->isSiteLevel());
    }

    /**
     * The direction lives in the key rather than in a substituted word, so
     * that a translator gets a whole sentence to rewrite instead of a frame
     * with a verb dropped into it.
     */
    public function testKeepsTheTwoDirectionsOfOneSentenceApart(): void
    {
        self::assertNotSame(Insight::SENTENCE_SITE_UP, Insight::SENTENCE_SITE_DOWN);
        self::assertNotSame(Insight::SENTENCE_PAGE_RISE, Insight::SENTENCE_PAGE_FALL);
    }

    private function insight(string $sentenceKey): Insight
    {
        return new Insight('main', $sentenceKey, ['Page views', 30, '130', 30], TrendSentiment::Positive, 3.0);
    }
}
