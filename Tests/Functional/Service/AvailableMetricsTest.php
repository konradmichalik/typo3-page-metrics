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

use KonradMichalik\Typo3PageMetrics\Service\SiteMetricsOverviewBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * AvailableMetricsTest.
 *
 * Covers SiteMetricsOverviewBuilder::availableMetrics() where several sources
 * are relevant for the same site and reuse each other's labels. Needs its own
 * test class because testExtensionsToLoad is fixed per class and this loads a
 * third-party source fixture on top of the two built-in ones.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AvailableMetricsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'konradmichalik/typo3-page-metrics',
        __DIR__.'/Fixtures/Extensions/page_metrics_demo_source',
    ];

    private SiteMetricsOverviewBuilder $subject;
    private string $keyFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->subject = $this->get(SiteMetricsOverviewBuilder::class);

        $this->keyFilePath = (string) tempnam(sys_get_temp_dir(), 'ga4-key-');
        file_put_contents($this->keyFilePath, json_encode([
            'client_email' => 'test@example.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nFAKE\n-----END PRIVATE KEY-----\n",
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->keyFilePath)) {
            unlink($this->keyFilePath);
        }

        parent::tearDown();
    }

    public function testPrefixesOnlyThoseLabelsSeveralRelevantSourcesShare(): void
    {
        $metrics = $this->subject->availableMetrics([$this->writeSiteWithBothBuiltInSources()]);

        // "Page views" exists in Matomo, GA4 and the third-party fixture, so
        // every one of them is disambiguated by its source's display name.
        self::assertSame('Matomo: Page views', $metrics['matomo.pageviews']);
        self::assertSame('GA4: Page views', $metrics['ga4.pageviews']);

        // A label unique among the relevant sources stays as it is.
        self::assertSame('Visits', $metrics['matomo.visits']);
    }

    public function testFallsBackToACapitalizedIdentifierForAThirdPartySource(): void
    {
        $metrics = $this->subject->availableMetrics([$this->writeSiteWithBothBuiltInSources()]);

        self::assertSame('Demo: Page views', $metrics['demo.pageviews']);
    }

    private function writeSiteWithBothBuiltInSources(): Site
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                0 => ['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us'],
            ],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => [
                'sources' => [
                    'matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 1, 'tokenAuth' => 'x'],
                    'ga4' => ['propertyId' => '123456789', 'serviceAccountKeyFilePath' => $this->keyFilePath],
                ],
            ],
        ]);

        return $this->get(SiteFinder::class)->getSiteByIdentifier('main');
    }
}
