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
use KonradMichalik\Typo3PageMetrics\Configuration\SiteConfigurationReader;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use KonradMichalik\Typo3PageMetrics\Provider\Typo3\{PageChangeLookup, Typo3AnnotationProvider};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function iterator_to_array;

/**
 * Typo3AnnotationProviderTest.
 *
 * Rows are inserted relative to today rather than imported from a CSV,
 * because the provider only looks a fixed number of days back: a fixture
 * with absolute timestamps would stop producing markers once the suite is
 * run far enough in the future.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Typo3AnnotationProviderTest extends FunctionalTestCase
{
    // typo3/cms-redirects provides the sys_redirect table; it is not part of
    // the core extensions loaded by default, so it must be declared here.
    protected array $coreExtensionsToLoad = ['redirects'];

    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private Typo3AnnotationProvider $subject;
    private DateRange $range;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/tt_content.csv');
        $this->subject = new Typo3AnnotationProvider(
            $this->get(SiteConfigurationReader::class),
            $this->get(PageChangeLookup::class),
        );

        $today = new DateTimeImmutable('today');
        $this->range = new DateRange($today->modify('-400 days'), $today);
    }

    public function testGetIdentifierReturnsTypo3(): void
    {
        self::assertSame('typo3', $this->subject->getIdentifier());
    }

    public function testSupportsAnEnabledSiteWithoutAnyAnnotationConfiguration(): void
    {
        self::assertTrue($this->subject->supports($this->site(['enabled' => true])));
    }

    public function testDoesNotSupportASiteThatTurnedAutomaticMarkersOff(): void
    {
        self::assertFalse($this->subject->supports($this->site([
            'enabled' => true,
            'annotations' => ['automatic' => false],
        ])));
    }

    public function testDoesNotSupportASiteThatIsNotEnabledAtAll(): void
    {
        self::assertFalse($this->subject->supports($this->site(['enabled' => false])));
    }

    public function testDerivesOneContentMarkerForSeveralEditsOnTheSameDay(): void
    {
        $day = new DateTimeImmutable('today -3 days');
        $this->recordEdit('pages', 2, $day);
        $this->recordEdit('pages', 2, $day);
        $this->recordEdit('tt_content', 100, $day);

        $annotations = $this->fetch();

        self::assertCount(1, $annotations);
        self::assertSame('content-2-'.$day->format('Ymd'), $annotations[0]->id);
        self::assertSame($day->format('Y-m-d'), $annotations[0]->date->format('Y-m-d'));
        self::assertSame(AnnotationCategory::Content, $annotations[0]->category);
        self::assertSame(2, $annotations[0]->pageUid);
        self::assertSame('Page updated, 3 changes', $annotations[0]->text);
        self::assertFalse($annotations[0]->isRange);
        self::assertFalse($annotations[0]->includeSubpages);
    }

    public function testASingleEditReadsAsOneChange(): void
    {
        $day = new DateTimeImmutable('today -3 days');
        $this->recordEdit('pages', 2, $day);

        $annotations = $this->fetch();

        self::assertSame('Page updated', $annotations[0]->text);
    }

    public function testDerivesAnSeoMarkerFromACreatedRedirect(): void
    {
        $day = new DateTimeImmutable('today -4 days');
        $this->recordRedirect('t3://page?uid=2', $day);

        $annotations = $this->fetch();

        self::assertCount(1, $annotations);
        self::assertSame('seo-2-'.$day->format('Ymd'), $annotations[0]->id);
        self::assertSame(AnnotationCategory::Seo, $annotations[0]->category);
        self::assertSame('Redirect created', $annotations[0]->text);
    }

    public function testIgnoresActivityOlderThanTheLookbackWindow(): void
    {
        // The requested range covers 400 days; the provider narrows it to the
        // 90 days any surface actually draws.
        $this->recordEdit('pages', 2, new DateTimeImmutable('today -120 days'));

        self::assertSame([], $this->fetch());
    }

    public function testTheMarkerTextNamesNoBackendUser(): void
    {
        // recordEdit() writes userid 42, the one thing a marker must never
        // carry onto a chart.
        $day = new DateTimeImmutable('today -3 days');
        $this->recordEdit('pages', 2, $day);

        $annotations = $this->fetch();

        self::assertStringNotContainsString('42', $annotations[0]->text);
    }

    /**
     * @return list<Annotation>
     */
    private function fetch(): array
    {
        return iterator_to_array($this->subject->fetch($this->site(['enabled' => true]), $this->range), false);
    }

    /**
     * @param array<string, mixed> $pageMetrics
     */
    private function site(array $pageMetrics): Site
    {
        return new Site('main', 1, ['base' => 'https://example.com/', 'pageMetrics' => $pageMetrics]);
    }

    private function recordEdit(string $table, int $recordUid, DateTimeImmutable $day): void
    {
        // Midday, so the server's time zone cannot move the row to the
        // neighbouring day. userid is written because real rows carry one,
        // and the marker must still say nothing about it.
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_history')->insert('sys_history', [
            'tablename' => $table,
            'recuid' => $recordUid,
            'actiontype' => 2,
            'usertype' => 'BE',
            'userid' => 42,
            'workspace' => 0,
            'tstamp' => $day->setTime(12, 0)->getTimestamp(),
        ]);
    }

    private function recordRedirect(string $target, DateTimeImmutable $day): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_redirect')->insert('sys_redirect', [
            'pid' => 0,
            'deleted' => 0,
            'disabled' => 0,
            'source_host' => '*',
            'source_path' => '/old-about',
            'target' => $target,
            'target_statuscode' => 307,
            'createdon' => $day->setTime(12, 0)->getTimestamp(),
        ]);
    }
}
