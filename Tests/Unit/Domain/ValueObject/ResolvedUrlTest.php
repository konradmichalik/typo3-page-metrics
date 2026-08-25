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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\ResolvedUrl;
use PHPUnit\Framework\TestCase;


/**
 * ResolvedUrlTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */

final class ResolvedUrlTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $resolved = new ResolvedUrl(12, 1, true);

        self::assertSame(12, $resolved->pageUid);
        self::assertSame(1, $resolved->languageUid);
        self::assertTrue($resolved->viaRedirect);
    }
}
