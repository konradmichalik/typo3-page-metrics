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

use KonradMichalik\Typo3PageMetrics\Service\MetricSourceLabelProvider;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * MetricSourceLabelProviderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MetricSourceLabelProviderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private MetricSourceLabelProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Controller/Ajax/Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $this->subject = $this->get(MetricSourceLabelProvider::class);
    }

    public function testNamesEveryBundledProvider(): void
    {
        self::assertSame('Matomo', $this->subject->for('matomo'));
        self::assertSame('Google Analytics 4', $this->subject->for('ga4'));
        self::assertSame('Access log', $this->subject->for('accesslog'));
    }

    public function testFallsBackToTheIdentifierOfAProviderTheXlfDoesNotName(): void
    {
        // A third-party provider ships no trans-unit in this extension's XLF.
        // Its own identifier is a worse label than a real name and a much
        // better one than an empty tooltip.
        self::assertSame('plausible', $this->subject->for('plausible'));
    }
}
