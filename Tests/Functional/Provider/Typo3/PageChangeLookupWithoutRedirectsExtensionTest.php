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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Provider\Typo3;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Provider\Typo3\PageChangeLookup;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PageChangeLookupWithoutRedirectsExtensionTest.
 *
 * typo3/cms-redirects is a require-dev-only dependency, so this test
 * deliberately does NOT load the "redirects" core extension: sys_redirect
 * genuinely does not exist in this instance, which is what an installation
 * without EXT:redirects looks like. The content markers must keep coming
 * either way, the same graceful degradation
 * RedirectFollowerWithoutRedirectsExtensionTest asserts for the resolver.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageChangeLookupWithoutRedirectsExtensionTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private PageChangeLookup $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_history.csv');
        $this->subject = $this->get(PageChangeLookup::class);
    }

    public function testStillReportsContentChangesAndNoSeoChanges(): void
    {
        $changes = $this->subject->changesFor(
            new Site('main', 1, ['base' => 'https://example.com/']),
            new DateRange(new DateTimeImmutable('2026-09-08'), new DateTimeImmutable('2026-09-14')),
        );

        self::assertNotSame([], $changes);

        foreach ($changes as $change) {
            self::assertSame(AnnotationCategory::Content, $change->category);
        }
    }
}
