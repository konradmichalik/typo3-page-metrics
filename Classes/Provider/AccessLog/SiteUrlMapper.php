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

use KonradMichalik\Typo3PageMetrics\Analysis\Parser\LogLine;
use TYPO3\CMS\Core\Site\Entity\Site;

use function array_filter;
use function explode;
use function is_array;
use function is_string;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * SiteUrlMapper.
 *
 * Decides which absolute URL a parsed log line is counted under for one
 * site, and whether it belongs to that site at all.
 *
 * A LogFormat ending in `%v` writes the virtual host that served each
 * line. Shared hosting commonly puts several vhosts into one combined
 * log, so a pathPattern matching such a file hands every site every other
 * site's lines; counting them all would make each site's numbers wrong
 * with nothing in the output to show it. A line whose host names none of
 * this site's own hosts is therefore dropped here.
 *
 * Dropped rather than carried forward under its own host: the resolver
 * matches a URL against every configured site, so a foreign host that is
 * itself a TYPO3 site in this installation would resolve onto that site's
 * pages, and this site's import would write rows for them. What the
 * resolver would call a foreign-host miss is only a miss from the site
 * currently being imported; the site the line actually belongs to counts
 * it when its own import reads the same log.
 *
 * A log with no vhost token cannot say which host served a line, so every
 * line is this site's. That is the single-vhost format, it is what these
 * files looked like before the token was read at all, and the behaviour is
 * unchanged for it.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SiteUrlMapper
{
    /**
     * @param array<string, string> $hostPrefixes host => scheme://host
     */
    private function __construct(
        private string $baseUrl,
        private array $hostPrefixes,
    ) {}

    /**
     * Every host the site answers on: its own base, every language base
     * (a language can live on its own domain, and which language a URL
     * belongs to has to survive as far as the resolver), and the
     * additionalHosts the resolver's site matching already honours.
     *
     * additionalHosts is read from the raw site configuration rather than
     * through SiteConfigurationReader, for the reason
     * SiteMatcher::additionalHosts() gives: this is host matching, and the
     * validation of that key belongs to the one read() ImportCommand
     * already performs per site.
     */
    public static function forSite(Site $site): self
    {
        $base = $site->getBase();
        $prefixes = [];

        $bases = [$base];
        foreach ($site->getLanguages() as $language) {
            $bases[] = $language->getBase();
        }

        foreach ($bases as $languageBase) {
            $host = self::normalizeHost($languageBase->getHost());

            if ('' !== $host) {
                $prefixes[$host] = $languageBase->getScheme().'://'.$host;
            }
        }

        $additionalHosts = $site->getConfiguration()['pageMetrics']['resolution']['additionalHosts'] ?? [];

        if (is_array($additionalHosts)) {
            foreach (array_filter($additionalHosts, is_string(...)) as $additionalHost) {
                $host = self::normalizeHost($additionalHost);

                if ('' !== $host) {
                    $prefixes[$host] = $base->getScheme().'://'.$host;
                }
            }
        }

        // Carries the port a base may name (a local instance on :8080),
        // which the per-host prefixes drop: host matching ignores ports,
        // and this is the one URL shape the provider built before there
        // was any host matching at all.
        return new self($base->getScheme().'://'.$base->getAuthority(), $prefixes);
    }

    /**
     * The URL to count this line under, or null when another virtual host
     * served it.
     */
    public function urlFor(LogLine $line): ?string
    {
        if (null === $line->host) {
            return $this->baseUrl.$line->target;
        }

        $host = self::normalizeHost($line->host);
        // Also without the www, and also with it. Apache writes the vhost's
        // canonical ServerName into %v, which is routinely the bare domain
        // while the TYPO3 base names the www one (or the other way round),
        // and a site configured that way would otherwise have every single
        // line dropped as another vhost's. Only this one pair is treated as
        // the same host: any other alias is a real decision and belongs in
        // pageMetrics.resolution.additionalHosts, where it is readable.
        $prefix = $this->hostPrefixes[$host]
            ?? $this->hostPrefixes[self::withoutWww($host)]
            ?? $this->hostPrefixes['www.'.$host]
            ?? null;

        return null !== $prefix ? $prefix.$line->target : null;
    }

    /**
     * The token is written by the server, not sent by the client, but its
     * spelling still varies: `%v` is the canonical ServerName, while a
     * LogFormat using `%{Host}i` writes whatever the request asked for,
     * port and capitalisation included.
     */
    private static function normalizeHost(string $host): string
    {
        return strtolower(explode(':', $host, 2)[0]);
    }

    private static function withoutWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
