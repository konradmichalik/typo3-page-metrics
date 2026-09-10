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

use KonradMichalik\Typo3PageMetrics\Provider\AccessLog\BotFilter;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * BotFilterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class BotFilterTest extends TestCase
{
    private BotFilter $subject;

    protected function setUp(): void
    {
        $this->subject = new BotFilter();
    }

    public function testIdentifiesKnownBotUserAgents(): void
    {
        $botUserAgents = [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
            'SomeVendorCrawler/1.0',
            'WebSpider 3.2',
            'curl/8.4.0',
            'Wget/1.21.3',
            'python-requests/2.31.0',
            '',
        ];

        foreach ($botUserAgents as $userAgent) {
            self::assertTrue($this->subject->isBot($userAgent), sprintf('Expected "%s" to be flagged as a bot.', $userAgent));
        }
    }

    public function testDoesNotFlagOrdinaryBrowserUserAgents(): void
    {
        $humanUserAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        ];

        foreach ($humanUserAgents as $userAgent) {
            self::assertFalse($this->subject->isBot($userAgent), sprintf('Did not expect "%s" to be flagged as a bot.', $userAgent));
        }
    }
}
