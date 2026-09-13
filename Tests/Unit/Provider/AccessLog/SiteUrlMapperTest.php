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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Provider\AccessLog;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\LogLine;
use KonradMichalik\Typo3PageMetrics\Provider\AccessLog\SiteUrlMapper;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * SiteUrlMapperTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SiteUrlMapperTest extends TestCase
{
    public function testCountsALineWithoutAVirtualHostTokenUnderTheSiteBase(): void
    {
        $mapper = SiteUrlMapper::forSite(new Site('main', 1, ['base' => 'https://example.com/']));

        self::assertSame('https://example.com/about', $mapper->urlFor($this->line('/about', null)));
    }

    public function testKeepsThePortOfASiteBaseForALogWithoutAVirtualHostToken(): void
    {
        $mapper = SiteUrlMapper::forSite(new Site('main', 1, ['base' => 'https://example.com:8080/']));

        self::assertSame('https://example.com:8080/about', $mapper->urlFor($this->line('/about', null)));
    }

    public function testCountsALineServedByTheSiteBaseHost(): void
    {
        $mapper = SiteUrlMapper::forSite(new Site('main', 1, ['base' => 'https://example.com/']));

        self::assertSame('https://example.com/about', $mapper->urlFor($this->line('/about', 'example.com')));
    }

    public function testRejectsALineServedByAnotherVirtualHost(): void
    {
        $mapper = SiteUrlMapper::forSite(new Site('main', 1, ['base' => 'https://example.com/']));

        self::assertNull($mapper->urlFor($this->line('/about', 'other-site.example')));
    }

    public function testCountsALineWhoseCanonicalServerNameDropsTheWwwOfTheSiteBase(): void
    {
        // Apache writes the vhost's canonical ServerName into %v, which is
        // routinely the bare domain while the site base names the www one.
        // Without this the site would lose every single line, silently.
        $mapper = SiteUrlMapper::forSite(new Site('main', 1, ['base' => 'https://www.example.com/']));

        self::assertSame('https://www.example.com/about', $mapper->urlFor($this->line('/about', 'example.com')));
    }

    public function testCountsALineWhoseCanonicalServerNameAddsAWwwTheSiteBaseOmits(): void
    {
        $mapper = SiteUrlMapper::forSite(new Site('main', 1, ['base' => 'https://example.com/']));

        self::assertSame('https://example.com/about', $mapper->urlFor($this->line('/about', 'www.example.com')));
    }

    public function testTreatsOnlyTheWwwPairAsTheSameHost(): void
    {
        // Any other alias is a real decision and belongs in additionalHosts.
        $mapper = SiteUrlMapper::forSite(new Site('main', 1, ['base' => 'https://example.com/']));

        self::assertNull($mapper->urlFor($this->line('/about', 'shop.example.com')));
    }

    public function testCountsALineServedByAnAdditionalHost(): void
    {
        $mapper = SiteUrlMapper::forSite(new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['resolution' => ['additionalHosts' => ['www.example.com']]],
        ]));

        self::assertSame('https://www.example.com/about', $mapper->urlFor($this->line('/about', 'www.example.com')));
    }

    public function testCountsALineServedByALanguageDomain(): void
    {
        $mapper = SiteUrlMapper::forSite(new Site('main', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => 0, 'locale' => 'en_US.UTF-8', 'base' => 'https://example.com/'],
                ['languageId' => 1, 'locale' => 'de_DE.UTF-8', 'base' => 'https://de.example.com/'],
            ],
        ]));

        self::assertSame('https://de.example.com/ueber-uns', $mapper->urlFor($this->line('/ueber-uns', 'de.example.com')));
    }

    public function testIgnoresCaseAndAPortInTheVirtualHostToken(): void
    {
        $mapper = SiteUrlMapper::forSite(new Site('main', 1, ['base' => 'https://example.com/']));

        self::assertSame('https://example.com/about', $mapper->urlFor($this->line('/about', 'EXAMPLE.COM:443')));
    }

    public function testIgnoresAnAdditionalHostsValueThatIsNotAListOfStrings(): void
    {
        // Validating that key is SiteConfigurationReader's job, once per
        // site; here a malformed value must simply not match anything.
        //
        // Probed with a host the www pair does not already cover, so the
        // assertion is about additionalHosts and not about that rule.
        $mapper = SiteUrlMapper::forSite(new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => ['resolution' => ['additionalHosts' => 'shop.example.com']],
        ]));

        self::assertNull($mapper->urlFor($this->line('/about', 'shop.example.com')));
        self::assertSame('https://example.com/about', $mapper->urlFor($this->line('/about', 'example.com')));
    }

    private function line(string $target, ?string $host): LogLine
    {
        return new LogLine(
            '203.0.113.1',
            new DateTimeImmutable('2026-10-10 10:00:00'),
            'GET',
            $target,
            'HTTP/1.1',
            200,
            512,
            null,
            'BrowserA',
            $host,
        );
    }
}
