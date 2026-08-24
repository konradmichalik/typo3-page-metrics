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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Resolver;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;
use KonradMichalik\Typo3PageMetrics\Resolver\PathClassifier;
use PHPUnit\Framework\TestCase;

final class PathClassifierTest extends TestCase
{
    private PathClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new PathClassifier();
    }

    public function testClassifiesFileadminPathAsFile(): void
    {
        self::assertSame(UnresolvedReason::File, $this->classifier->classify('/fileadmin/images/foo.jpg'));
    }

    public function testClassifiesUploadsPathAsFile(): void
    {
        self::assertSame(UnresolvedReason::File, $this->classifier->classify('/uploads/tx_news/foo.pdf'));
    }

    public function testClassifiesApiPathAsNonPage(): void
    {
        self::assertSame(UnresolvedReason::NonPage, $this->classifier->classify('/api/v1/status'));
    }

    public function testClassifiesTypo3BackendPathAsNonPage(): void
    {
        self::assertSame(UnresolvedReason::NonPage, $this->classifier->classify('/typo3/module/web/layout'));
    }

    public function testClassifiesUnrecognizedPathAsUnknown(): void
    {
        self::assertSame(UnresolvedReason::Unknown, $this->classifier->classify('/some/removed/page'));
    }
}
