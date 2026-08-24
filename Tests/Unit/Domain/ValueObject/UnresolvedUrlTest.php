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
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\UnresolvedUrl;
use PHPUnit\Framework\TestCase;

final class UnresolvedUrlTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $unresolved = new UnresolvedUrl('https://other-host.example/', UnresolvedReason::ForeignHost);

        self::assertSame('https://other-host.example/', $unresolved->url);
        self::assertSame(UnresolvedReason::ForeignHost, $unresolved->reason);
    }
}
