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

use KonradMichalik\Typo3PageMetrics\Provider\AccessLog\AccessLogConfigurationResolver;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * AccessLogConfigurationResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AccessLogConfigurationResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private AccessLogConfigurationResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(AccessLogConfigurationResolver::class);
    }

    public function testResolvesOwnSiteConfigurationWhenPresent(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'providers' => [
                    'accesslog' => ['pathPattern' => '/var/log/nginx/example.com-access.log*'],
                ],
            ],
        ]);

        $configuration = $this->subject->resolve($site);

        self::assertNotNull($configuration);
        self::assertSame('/var/log/nginx/example.com-access.log*', $configuration->pathPattern);
    }

    public function testReturnsNullWhenNothingIsConfigured(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.com/']);

        self::assertNull($this->subject->resolve($site));
    }

    public function testReturnsNullForAnEmptyPathPattern(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'providers' => [
                    'accesslog' => ['pathPattern' => ''],
                ],
            ],
        ]);

        self::assertNull($this->subject->resolve($site));
    }
}
