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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Source\Matomo;

use KonradMichalik\Typo3PageMetrics\Source\Matomo\MatomoConfigurationResolver;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;


/**
 * MatomoConfigurationResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */

final class MatomoConfigurationResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private MatomoConfigurationResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(MatomoConfigurationResolver::class);
    }

    public function testResolvesOwnSiteConfigurationWhenPresent(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'sources' => [
                    'matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 3, 'tokenAuth' => 'abc123'],
                ],
            ],
        ]);

        $configuration = $this->subject->resolve($site);

        self::assertNotNull($configuration);
        self::assertSame('https://matomo.example.com/', $configuration->instanceUrl);
        self::assertSame(3, $configuration->idSite);
        self::assertSame('abc123', $configuration->token);
    }

    public function testReturnsNullWhenNothingIsConfiguredAndMatomoWidgetsIsNotLoaded(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.com/']);

        self::assertNull($this->subject->resolve($site));
    }

    public function testIgnoresIncompleteOwnConfigurationAndFallsThrough(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'sources' => [
                    'matomo' => ['endpoint' => 'https://matomo.example.com/'],
                ],
            ],
        ]);

        self::assertNull($this->subject->resolve($site));
    }
}
