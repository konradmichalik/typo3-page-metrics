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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Resolver;

use KonradMichalik\Typo3PageMetrics\Resolver\LanguageMatcher;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;


/**
 * LanguageMatcherTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */

final class LanguageMatcherTest extends TestCase
{
    private LanguageMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new LanguageMatcher();
    }

    public function testMatchesDefaultLanguageForRootPath(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'languageId' => 0,
                    'locale' => 'de_DE.UTF-8',
                    'base' => '/',
                ],
            ],
        ]);

        $language = $this->matcher->match('https://example.com/foo', $site);

        self::assertNotNull($language);
        self::assertSame(0, $language->getLanguageId());
    }

    public function testPrefersMoreSpecificPathPrefixOverDefault(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'languageId' => 0,
                    'locale' => 'de_DE.UTF-8',
                    'base' => '/',
                ],
                [
                    'languageId' => 1,
                    'locale' => 'en_US.UTF-8',
                    'base' => '/en/',
                ],
            ],
        ]);

        $language = $this->matcher->match('https://example.com/en/foo', $site);

        self::assertNotNull($language);
        self::assertSame(1, $language->getLanguageId());
    }

    public function testFallsBackToDefaultWhenNoSpecificPrefixMatches(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'languageId' => 0,
                    'locale' => 'de_DE.UTF-8',
                    'base' => '/',
                ],
                [
                    'languageId' => 1,
                    'locale' => 'en_US.UTF-8',
                    'base' => '/en/',
                ],
            ],
        ]);

        $language = $this->matcher->match('https://example.com/foo', $site);

        self::assertNotNull($language);
        self::assertSame(0, $language->getLanguageId());
    }

    public function testMatchesLanguageViaConfiguredAdditionalHost(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://site.ddev.site/',
            'pageMetrics' => ['resolution' => ['additionalHosts' => ['www.live.example']]],
            'languages' => [
                [
                    'languageId' => 0,
                    'locale' => 'de_DE.UTF-8',
                    'base' => '/',
                ],
            ],
        ]);

        $language = $this->matcher->match('https://www.live.example/foo', $site);

        self::assertNotNull($language);
        self::assertSame(0, $language->getLanguageId());
    }

    public function testDoesNotMatchPathThatSharesPrefixButNotAtSegmentBoundary(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'languageId' => 0,
                    'locale' => 'de_DE.UTF-8',
                    'base' => '/',
                ],
                [
                    'languageId' => 1,
                    'locale' => 'en_US.UTF-8',
                    'base' => '/en/',
                ],
            ],
        ]);

        $language = $this->matcher->match('https://example.com/english/page', $site);

        self::assertNotNull($language);
        self::assertSame(0, $language->getLanguageId());
    }
}
