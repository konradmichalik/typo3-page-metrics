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

use KonradMichalik\Typo3PageMetrics\Resolver\RedirectFollower;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * RedirectFollowerWithoutRedirectsExtensionTest.
 *
 * typo3/cms-redirects is a require-dev-only dependency (see composer.json):
 * resolver stages must stay swappable/optional, so RedirectFollower must not
 * force every installation to install the whole redirects system extension.
 * This test intentionally does NOT load the "redirects" core extension, so
 * the sys_redirect table genuinely does not exist in this test instance,
 * reproducing what a real installation without EXT:redirects looks like.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RedirectFollowerWithoutRedirectsExtensionTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private RedirectFollower $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(RedirectFollower::class);
    }

    public function testReturnsNullWhenSysRedirectTableDoesNotExist(): void
    {
        self::assertNull($this->subject->findTarget('example.com', '/old-about'));
    }
}
