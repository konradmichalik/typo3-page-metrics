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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Resolver;

use KonradMichalik\Typo3PageMetrics\Resolver\SlugLookup;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SlugLookupTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SlugLookupTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private SlugLookup $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->subject = $this->get(SlugLookup::class);
    }

    public function testFindsDefaultLanguagePageBySlug(): void
    {
        self::assertSame(2, $this->subject->find('/about', 0));
    }

    public function testFindsRootPageBySlashSlug(): void
    {
        self::assertSame(1, $this->subject->find('/', 0));
    }

    public function testResolvesTranslatedPageToDefaultLanguageParentUid(): void
    {
        self::assertSame(2, $this->subject->find('/en/about', 1));
    }

    public function testReturnsNullWhenNoSlugMatches(): void
    {
        self::assertNull($this->subject->find('/does-not-exist', 0));
    }

    public function testReturnsNullWhenSlugExistsOnlyInAnotherLanguage(): void
    {
        self::assertNull($this->subject->find('/en/about', 0));
    }
}
