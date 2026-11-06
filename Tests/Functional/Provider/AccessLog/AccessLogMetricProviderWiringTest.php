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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Provider\AccessLog;

use KonradMichalik\Typo3PageMetrics\Service\SiteMetricsOverviewBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * AccessLogMetricProviderWiringTest.
 *
 * Confirms AccessLogMetricProvider is actually registered under the
 * page_metrics.metric_provider DI tag and picked up through the real
 * container - the unit tests construct it directly and never exercise that
 * wiring.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AccessLogMetricProviderWiringTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../../Service/Fixtures/pages.csv');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'accesslog-');
        // availableMetrics() labels the "Bot traffic" summary through it.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testAvailableMetricsIncludesAccessLogMetricsWhenConfigured(): void
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
                'providers' => [
                    'accesslog' => ['pathPattern' => $this->logFile],
                ],
            ],
        ]);
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('main');

        $metrics = $this->get(SiteMetricsOverviewBuilder::class)->availableMetrics([$site]);

        self::assertSame('Page views', $metrics['accesslog.pageviews'] ?? null);
        self::assertSame('Visits', $metrics['accesslog.visits'] ?? null);
    }
}
