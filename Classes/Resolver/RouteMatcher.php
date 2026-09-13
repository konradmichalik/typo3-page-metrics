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

namespace KonradMichalik\Typo3PageMetrics\Resolver;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\RouteMatch;
use Throwable;
use TYPO3\CMS\Core\Http\{ServerRequest, Uri};
use TYPO3\CMS\Core\Routing\{PageArguments, SiteRouteResult};
use TYPO3\CMS\Core\Site\Entity\{Site, SiteLanguage};

use function is_array;

/**
 * RouteMatcher.
 *
 * Last stage of the resolver chain: asks TYPO3's own PageRouter which page
 * would have rendered a URL that matches no page slug. That is how a record
 * detail URL (/news/my-article, produced by a route enhancer) gets counted
 * for the page that renders it instead of being thrown away as unresolved.
 *
 * Running the site's real routing configuration, rather than walking up to
 * the nearest ancestor slug, is deliberate: it is the only thing that can
 * tell /news/my-article from /news/this-never-existed. A persisted mapper
 * looks the segment up in the record table, so a URL only resolves here if
 * TYPO3 would actually have served it. Anything else stays unresolved and
 * keeps showing up in the "Not found pages" report, which is the whole
 * reason that report exists.
 *
 * The same match also says which record the page is showing, where the route
 * was about one: see RecordArgumentExtractor, which reads it out of the
 * arguments this match already produced.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteMatcher
{
    public function __construct(
        private RecordArgumentExtractor $recordArgumentExtractor,
    ) {}

    /**
     * @param string $url  the normalized absolute URL, used to build the request
     * @param string $path the path with the site/language base already stripped
     *
     * @return RouteMatch|null the rendering page in the default language plus
     *                         the record it shows, or null if the site's
     *                         routing would not serve this URL
     */
    public function match(Site $site, ?SiteLanguage $language, string $url, string $path): ?RouteMatch
    {
        // Without enhancers the router can only ever match a plain page slug,
        // which SlugLookup has already tried by the time this runs. Skipping
        // is therefore free of behaviour and free of cost: a site that does
        // not route records pays no extra query per unresolved URL.
        if (!$this->hasRouteEnhancers($site)) {
            return null;
        }

        $uri = new Uri($url);

        try {
            $result = $site->getRouter()->matchRequest(
                new ServerRequest($uri),
                new SiteRouteResult($uri, $site, $language ?? $site->getDefaultLanguage(), $path),
            );
        } catch (Throwable) {
            // RouteNotFoundException is the ordinary "no page renders this",
            // but an enhancer or mapper class may also come from an extension
            // that is no longer installed. Same stance as RedirectFollower:
            // from the resolver's side both are indistinguishable from "no
            // match", and one broken site configuration must not abort an
            // import run.
            return null;
        }

        if (!$result instanceof PageArguments) {
            return null;
        }

        $pageUid = $result->getPageId();

        if ($pageUid <= 0) {
            return null;
        }

        return new RouteMatch($pageUid, $this->recordArgumentExtractor->extract($site, $result));
    }

    private function hasRouteEnhancers(Site $site): bool
    {
        $enhancers = $site->getConfiguration()['routeEnhancers'] ?? null;

        return is_array($enhancers) && [] !== $enhancers;
    }
}
