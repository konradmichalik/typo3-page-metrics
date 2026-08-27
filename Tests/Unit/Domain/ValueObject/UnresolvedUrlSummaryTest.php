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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\UnresolvedUrlSummary;
use PHPUnit\Framework\TestCase;

/**
 * UnresolvedUrlSummaryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UnresolvedUrlSummaryTest extends TestCase
{
    public function testPathStripsSchemeAndHost(): void
    {
        $summary = new UnresolvedUrlSummary('https://example.com/downloads/brochure.pdf', UnresolvedReason::File, 1);

        self::assertSame('/downloads/brochure.pdf', $summary->path);
    }

    public function testPathKeepsTheQueryString(): void
    {
        $summary = new UnresolvedUrlSummary('https://example.com/?utm_broken=1', UnresolvedReason::Unknown, 1);

        self::assertSame('/?utm_broken=1', $summary->path);
    }

    public function testPathDefaultsToASlashForABareHost(): void
    {
        $summary = new UnresolvedUrlSummary('https://example.com', UnresolvedReason::NonPage, 1);

        self::assertSame('/', $summary->path);
    }
}
