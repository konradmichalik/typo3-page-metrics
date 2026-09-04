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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Resolver;

use KonradMichalik\Typo3PageMetrics\Resolver\RedirectFollower;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * RedirectFollowerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RedirectFollowerTest extends FunctionalTestCase
{
    // typo3/cms-redirects provides the sys_redirect table; it is not part of
    // the core extensions loaded by default, so it must be declared here.
    protected array $coreExtensionsToLoad = ['redirects'];

    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private RedirectFollower $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_redirect.csv');
        // TYPO3 v14's testing-framework 9.x dropped SiteBasedTestTrait; the
        // core SiteWriter service is the direct replacement for building and
        // persisting a basic site configuration in functional tests.
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.com/');
        $this->subject = $this->get(RedirectFollower::class);
    }

    public function testFollowsWildcardHostRedirectToPageTarget(): void
    {
        self::assertSame('https://example.com/about', $this->subject->findTarget('example.com', '/old-about'));
    }

    public function testFollowsHostSpecificRedirectToExternalTarget(): void
    {
        self::assertSame('https://example.com/new-external', $this->subject->findTarget('example.com', '/old-external'));
    }

    public function testReturnsNullWhenNoRedirectMatches(): void
    {
        self::assertNull($this->subject->findTarget('example.com', '/no-redirect-here'));
    }

    public function testIgnoresDisabledRedirects(): void
    {
        self::assertNull($this->subject->findTarget('example.com', '/disabled-source'));
    }

    public function testOneHopOfAMutualRedirectPairResolvesToTheOtherPath(): void
    {
        // Cycle-safety (max 5 hops) is the Resolver orchestrator's job (Task 8) -
        // this only proves a single hop resolves correctly on its own.
        self::assertSame('/redirect-b', $this->subject->findTarget('example.com', '/redirect-a'));
        self::assertSame('/redirect-a', $this->subject->findTarget('example.com', '/redirect-b'));
    }

    public function testReturnsNullWhenAPageTargetPointsAtANonExistentPage(): void
    {
        self::assertNull($this->subject->findTarget('example.com', '/old-missing-page'));
    }

    public function testReturnsNullForATargetThatIsNeitherAPageNorAnAbsoluteOrRootRelativeUrl(): void
    {
        self::assertNull($this->subject->findTarget('example.com', '/old-relative'));
    }
}
