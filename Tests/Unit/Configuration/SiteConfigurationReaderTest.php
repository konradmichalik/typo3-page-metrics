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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Configuration;

use KonradMichalik\Typo3PageMetrics\Configuration\SiteConfigurationReader;
use KonradMichalik\Typo3PageMetrics\Exception\InvalidSiteConfigurationException;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * SiteConfigurationReaderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteConfigurationReaderTest extends TestCase
{
    private SiteConfigurationReader $subject;

    protected function setUp(): void
    {
        $this->subject = new SiteConfigurationReader();
    }

    public function testReturnsDisabledDefaultWhenPageMetricsKeyIsMissing(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.com/']);

        $configuration = $this->subject->read($site);

        self::assertFalse($configuration->enabled);
        self::assertSame([], $configuration->providers);
        self::assertTrue($configuration->dropQueryString);
        self::assertSame([], $configuration->keepQueryParams);
        self::assertSame(400, $configuration->retentionDays);
        self::assertSame([], $configuration->additionalHosts);
        self::assertNull($configuration->annotationStoragePid);
    }

    public function testReadsFullyConfiguredSite(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'enabled' => true,
                'providers' => [
                    'matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 3],
                ],
                'resolution' => [
                    'dropQueryString' => true,
                    'keepQueryParams' => ['tx_solr[q]'],
                ],
                'retentionDays' => 90,
            ],
        ]);

        $configuration = $this->subject->read($site);

        self::assertTrue($configuration->enabled);
        self::assertSame(['matomo' => ['endpoint' => 'https://matomo.example.com/', 'siteId' => 3]], $configuration->providers);
        self::assertSame(['tx_solr[q]'], $configuration->keepQueryParams);
        self::assertSame(90, $configuration->retentionDays);
    }

    public function testReadsAdditionalHosts(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://site.ddev.site/',
            'pageMetrics' => [
                'enabled' => true,
                'resolution' => [
                    'additionalHosts' => ['www.live.example'],
                ],
            ],
        ]);

        $configuration = $this->subject->read($site);

        self::assertSame(['www.live.example'], $configuration->additionalHosts);
    }

    public function testThrowsWhenAdditionalHostsIsNotAList(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => true, 'resolution' => ['additionalHosts' => ['key' => 'value']]],
        ]);

        $this->expectException(InvalidSiteConfigurationException::class);

        $this->subject->read($site);
    }

    public function testThrowsWhenEnabledIsNotBoolean(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => 'yes'],
        ]);

        $this->expectException(InvalidSiteConfigurationException::class);

        $this->subject->read($site);
    }

    public function testThrowsWhenRetentionDaysIsNotAPositiveInteger(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => true, 'retentionDays' => 0],
        ]);

        $this->expectException(InvalidSiteConfigurationException::class);

        $this->subject->read($site);
    }

    public function testThrowsWhenKeepQueryParamsIsNotAList(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => true, 'resolution' => ['keepQueryParams' => ['key' => 'value']]],
        ]);

        $this->expectException(InvalidSiteConfigurationException::class);

        $this->subject->read($site);
    }

    public function testThrowsWhenPageMetricsIsNotAMapping(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => 'yes please',
        ]);

        $this->expectException(InvalidSiteConfigurationException::class);
        $this->expectExceptionCode(1756000000);

        $this->subject->read($site);
    }

    public function testThrowsWhenProvidersIsNotAMapping(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => true, 'providers' => 'matomo'],
        ]);

        $this->expectException(InvalidSiteConfigurationException::class);
        $this->expectExceptionCode(1756000002);

        $this->subject->read($site);
    }

    public function testThrowsWhenResolutionIsNotAMapping(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => true, 'resolution' => 'strict'],
        ]);

        $this->expectException(InvalidSiteConfigurationException::class);
        $this->expectExceptionCode(1756000003);

        $this->subject->read($site);
    }

    public function testThrowsWhenDropQueryStringIsNotBoolean(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => true, 'resolution' => ['dropQueryString' => 'nope']],
        ]);

        $this->expectException(InvalidSiteConfigurationException::class);
        $this->expectExceptionCode(1756000004);

        $this->subject->read($site);
    }

    public function testReadsTheAnnotationStoragePage(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => true, 'annotations' => ['storagePid' => 42]],
        ]);

        $configuration = $this->subject->read($site);

        self::assertSame(42, $configuration->annotationStoragePid);
    }

    public function testLeavesTheAnnotationStoragePageUnsetWhenTheSiteNamesNone(): void
    {
        // Not an error: the markers such a site already has stay readable
        // and editable, it simply offers no shortcut for creating new ones.
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => true],
        ]);

        $configuration = $this->subject->read($site);

        self::assertNull($configuration->annotationStoragePid);
    }

    public function testThrowsWhenAnnotationsIsNotAMapping(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => true, 'annotations' => 42],
        ]);

        $this->expectException(InvalidSiteConfigurationException::class);
        $this->expectExceptionCode(1756000008);

        $this->subject->read($site);
    }

    public function testThrowsWhenTheAnnotationStoragePageIsNotAPositiveInteger(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['enabled' => true, 'annotations' => ['storagePid' => 0]],
        ]);

        $this->expectException(InvalidSiteConfigurationException::class);
        $this->expectExceptionCode(1756000009);

        $this->subject->read($site);
    }
}
