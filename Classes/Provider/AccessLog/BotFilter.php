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

namespace KonradMichalik\Typo3PageMetrics\Provider\AccessLog;

use function implode;
use function preg_match;
use function sprintf;

/**
 * BotFilter.
 *
 * A hard-coded signature list rather than a configurable one: this is a
 * best-effort filter for the well-known crawlers/scripts that would
 * otherwise dominate raw access-log counts (search engines, SEO tools,
 * generic HTTP clients), not an exhaustive or user-tunable bot database -
 * Matomo/GA4 already do that job properly via JS-based tracking, which a
 * plain access log has no equivalent of. An empty user agent is also
 * treated as a bot: browsers always send one, so its absence is itself a
 * signal of a script rather than a person.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class BotFilter
{
    private const SIGNATURES = [
        'bot', 'crawl', 'spider', 'slurp', 'archiver', 'facebookexternalhit',
        'curl', 'wget', 'python-requests', 'python-urllib', 'go-http-client',
        'okhttp', 'axios', 'libwww-perl', 'scrapy', 'headlesschrome',
        'phantomjs', 'monitor', 'pingdom', 'uptimerobot', 'ahrefs', 'semrush',
        'mj12', 'petalbot', 'dotbot', 'seznambot',
    ];

    // Built once per instance rather than on every isBot() call: this runs
    // once per parsed log line, easily hundreds of thousands of times for a
    // busy site's daily log, and self::SIGNATURES never changes at runtime.
    private string $pattern;

    public function __construct()
    {
        $this->pattern = sprintf('/%s/i', implode('|', self::SIGNATURES));
    }

    public function isBot(string $userAgent): bool
    {
        if ('' === $userAgent) {
            return true;
        }

        return 1 === preg_match($this->pattern, $userAgent);
    }
}
