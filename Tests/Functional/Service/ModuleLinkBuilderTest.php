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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Service;

use KonradMichalik\Typo3PageMetrics\Service\ModuleLinkBuilder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function urldecode;

/**
 * ModuleLinkBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ModuleLinkBuilderTest extends FunctionalTestCase
{
    private const READABLE_PAGE = 2;
    private const RESTRICTED_PAGE = 3;
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/module_readers.csv');
    }

    public function testPointsAtTheModulesOwnRouteForThePageInHand(): void
    {
        $this->setUpBackendUser(1);

        $link = urldecode((string) $this->subject()->forPage(self::READABLE_PAGE));

        // The generated path, not the route name: UriBuilder resolves
        // web_pagemetrics to the module's own URL before anyone sees it.
        self::assertStringContainsString('/typo3/module/web/pagemetrics', $link);
        self::assertStringContainsString('id='.self::READABLE_PAGE, $link);
    }

    public function testCarriesNoPeriodSoTheReadersOwnModulePeriodSurvivesTheClick(): void
    {
        // Not a display detail. `period` and `view` are moduleData keys, and
        // BackendModuleValidator persists a query parameter named like one
        // before the module ever sees the request, so a link carrying them
        // would not pre-select anything, it would rewrite what this user
        // gets on every later visit, including the ones from the menu.
        $this->setUpBackendUser(1);

        $link = urldecode((string) $this->subject()->forPage(self::READABLE_PAGE));

        self::assertStringNotContainsString('period=', $link);
        self::assertStringNotContainsString('view=', $link);
    }

    public function testOffersNoLinkWithoutAPage(): void
    {
        $this->setUpBackendUser(1);

        self::assertNull($this->subject()->forPage(0));
    }

    public function testOffersNoLinkWhereTheTilesThemselvesAreSwitchedOff(): void
    {
        // Same switch, same answer as the tiles and the module route: a user
        // it is set for gets a notice instead of the module, so a link there
        // would lead to one.
        $this->setUpBackendUser(22);

        self::assertNull($this->subject()->forPage(self::READABLE_PAGE));
    }

    public function testOffersNoLinkToAnEditorWhoseGroupDoesNotListTheModule(): void
    {
        // The module is declared 'access' => 'user', so a non-admin reaches
        // it only through be_groups.groupMods. Without that the route denies
        // them, which is the one case a link is worse than no link.
        $this->setUpBackendUser(21);

        self::assertNull($this->subject()->forPage(self::READABLE_PAGE));
    }

    public function testOffersTheLinkToAnEditorWhoseGroupListsTheModule(): void
    {
        $this->setUpBackendUser(20);

        self::assertNotNull($this->subject()->forPage(self::READABLE_PAGE));
    }

    public function testOffersNoLinkForAPageTheUserMayNotSee(): void
    {
        // The module checks PAGE_SHOW on the page it is handed and refuses
        // without it. A widget names a site's root page rather than a page
        // the reader picked, so the check belongs here too.
        $this->setUpBackendUser(20);

        self::assertNull($this->subject()->forPage(self::RESTRICTED_PAGE));
    }

    private function subject(): ModuleLinkBuilder
    {
        return $this->get(ModuleLinkBuilder::class);
    }
}
