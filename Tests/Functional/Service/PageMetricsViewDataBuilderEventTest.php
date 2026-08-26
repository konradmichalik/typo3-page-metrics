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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Service;

use KonradMichalik\Typo3PageMetrics\Service\PageMetricsViewDataBuilder;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PageMetricsViewDataBuilderEventTest.
 *
 * Kept separate from PageMetricsViewDataBuilderTest because $testExtensionsToLoad
 * is fixed for a whole test class - loading the page_metrics_demo_tiles fixture
 * extension here would add an extra tile to every test in that other class too.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PageMetricsViewDataBuilderEventTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'konradmichalik/typo3-page-metrics',
        __DIR__.'/Fixtures/Extensions/page_metrics_demo_tiles',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    public function testAThirdPartyExtensionCanRegisterATileViaTheEvent(): void
    {
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
            ],
            'errorHandling' => [],
            'routes' => [],
        ]);

        $viewData = $this->get(PageMetricsViewDataBuilder::class)->build(2, 0, 30);

        $demoTiles = array_values(array_filter($viewData->tiles, static fn ($tile) => 'demo.registered_via_event' === $tile->identifier));
        self::assertCount(1, $demoTiles);
        self::assertSame('Registered via event', $demoTiles[0]->label);
        self::assertSame(123, $demoTiles[0]->value);
    }
}
