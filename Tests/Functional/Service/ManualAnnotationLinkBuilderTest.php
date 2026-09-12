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

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Exception\InvalidSiteConfigurationException;
use KonradMichalik\Typo3PageMetrics\Service\ManualAnnotationLinkBuilder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function urldecode;

/**
 * ManualAnnotationLinkBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ManualAnnotationLinkBuilderTest extends FunctionalTestCase
{
    private const STORAGE_PAGE = 10;
    private const LOCKED_STORAGE_PAGE = 11;
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/annotation_storage_pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/annotation_editors.csv');
    }

    public function testOffersNoLinkWhenTheSiteNamesNoStoragePage(): void
    {
        $this->setUpBackendUser(1);

        $link = $this->subject()->forPage($this->site(null), 2, new DateTimeImmutable('2026-09-12'));

        self::assertNull($link);
    }

    public function testPreFillsSiteDateAndPageSoTheFormOpensOnTheDayAndPageItWasStartedFrom(): void
    {
        $this->setUpBackendUser(1);
        $date = new DateTimeImmutable('2026-09-12 00:00:00');

        $link = urldecode((string) $this->subject()->forPage($this->site(self::STORAGE_PAGE), 2, $date));

        self::assertStringContainsString('edit[tx_typo3pagemetrics_manual_annotation][10]=new', $link);
        self::assertStringContainsString('defVals[tx_typo3pagemetrics_manual_annotation][site_identifier]=main', $link);
        self::assertStringContainsString('defVals[tx_typo3pagemetrics_manual_annotation][date]='.$date->getTimestamp(), $link);
        self::assertStringContainsString('defVals[tx_typo3pagemetrics_manual_annotation][page_uid]=2', $link);
    }

    public function testReturnsToThePageTheMarkerWasStartedFrom(): void
    {
        $this->setUpBackendUser(1);

        $link = urldecode((string) $this->subject()->forPage($this->site(self::STORAGE_PAGE), 2, new DateTimeImmutable('2026-09-12')));

        // The generated path, not the route name: UriBuilder resolves
        // web_layout to the module's own URL before anyone sees it.
        self::assertStringContainsString('returnUrl=/typo3/module/web/layout', $link);
        self::assertStringContainsString('id=2', $link);
    }

    public function testAnEditorWhoMayAddContentToTheStoragePageGetsTheLink(): void
    {
        // No admin rights anywhere: the fixture group lists the table, the
        // storage page grants that group content-edit, and the page is in the
        // user's mounts. That is the whole of what TYPO3 asks for.
        $this->setUpBackendUser(10);

        $link = $this->subject()->forPage($this->site(self::STORAGE_PAGE), 2, new DateTimeImmutable('2026-09-12'));

        self::assertNotNull($link);
    }

    public function testAnEditorWithoutEditAccessToTheStoragePageGetsNoLink(): void
    {
        // The locked storage page grants everybody "show" and nothing else,
        // so this user can see it in the tree and still not create a record
        // on it. Offering the entry would open a form that refuses to save.
        $this->setUpBackendUser(10);

        $link = $this->subject()->forPage($this->site(self::LOCKED_STORAGE_PAGE), 2, new DateTimeImmutable('2026-09-12'));

        self::assertNull($link);
    }

    public function testAnEditorWhoseGroupDoesNotAllowTheTableGetsNoLink(): void
    {
        // Page permissions alone are not enough: the group must list the
        // table under "Allowed tables" as well, which is the same pair of
        // checks DataHandler makes when the form is saved.
        $this->setUpBackendUser(11);

        $link = $this->subject()->forPage($this->site(self::STORAGE_PAGE), 2, new DateTimeImmutable('2026-09-12'));

        self::assertNull($link);
    }

    public function testRejectsAStoragePidThatIsNotAPageUid(): void
    {
        $this->setUpBackendUser(1);
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['annotations' => ['storagePid' => 'pages']],
        ]);

        $this->expectException(InvalidSiteConfigurationException::class);
        $this->expectExceptionCode(1756000009);

        $this->subject()->forPage($site, 2, new DateTimeImmutable('2026-09-12'));
    }

    private function subject(): ManualAnnotationLinkBuilder
    {
        return $this->get(ManualAnnotationLinkBuilder::class);
    }

    private function site(?int $storagePid): Site
    {
        $pageMetrics = ['providers' => ['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x']]];

        if (null !== $storagePid) {
            $pageMetrics['annotations'] = ['storagePid' => $storagePid];
        }

        return new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => $pageMetrics,
        ]);
    }
}
