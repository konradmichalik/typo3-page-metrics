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

namespace KonradMichalik\Typo3PageMetrics\Resolver;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{ResolvedUrl, UnresolvedUrl};
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Orchestrates Normalizer -> SiteMatcher -> LanguageMatcher -> SlugLookup ->
 * RedirectFollower -> PathClassifier. Resolution happens only at import
 * time; callers persist the result, this class never re-resolves live or
 * retroactively.
 *
 * @api
 */
final class Resolver
{
    /** @var int */
    private const MAX_REDIRECT_HOPS = 5;

    /** @var array<string, ResolvedUrl|UnresolvedUrl> */
    private array $cache = [];

    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly SiteMatcher $siteMatcher,
        private readonly LanguageMatcher $languageMatcher,
        private readonly SlugLookup $slugLookup,
        private readonly RedirectFollower $redirectFollower,
        private readonly PathClassifier $pathClassifier,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * @param list<string> $keepQueryParams
     */
    public function resolve(string $url, bool $dropQueryString = true, array $keepQueryParams = []): ResolvedUrl|UnresolvedUrl
    {
        $normalized = $this->normalizer->normalize($url, $dropQueryString, $keepQueryParams);

        if (isset($this->cache[$normalized])) {
            return $this->cache[$normalized];
        }

        $result = $this->resolveNormalized($normalized, $normalized, [], 0);

        return $this->cache[$normalized] = $result;
    }

    /**
     * Clears the in-memory resolution cache. Callers running a fresh import
     * run (e.g. Task 15's ImportCommand) call this between runs so cached
     * results never leak across runs of a shared/long-lived instance.
     */
    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * @param list<string> $visited
     */
    private function resolveNormalized(string $normalizedUrl, string $originalUrl, array $visited, int $hopCount, bool $viaRedirect = false): ResolvedUrl|UnresolvedUrl
    {
        if (in_array($normalizedUrl, $visited, true) || $hopCount > self::MAX_REDIRECT_HOPS) {
            return new UnresolvedUrl($originalUrl, UnresolvedReason::Unknown);
        }

        $visited[] = $normalizedUrl;

        $site = $this->siteMatcher->match($normalizedUrl, $this->siteFinder->getAllSites());

        if (null === $site) {
            return new UnresolvedUrl($originalUrl, UnresolvedReason::ForeignHost);
        }

        $urlParts = parse_url($normalizedUrl);
        $host = (string) ($urlParts['host'] ?? '');
        $path = (string) ($urlParts['path'] ?? '/');

        $language = $this->languageMatcher->match($normalizedUrl, $site);
        $languageId = $language?->getLanguageId() ?? 0;
        $slug = $this->stripBasePath($path, $language?->getBase()->getPath() ?? $site->getBase()->getPath());

        $pageUid = $this->slugLookup->find($slug, $languageId);

        if (null !== $pageUid) {
            return new ResolvedUrl($pageUid, $languageId, $viaRedirect);
        }

        $redirectTarget = $this->redirectFollower->findTarget($host, $path);

        if (null !== $redirectTarget) {
            $absoluteTarget = str_starts_with($redirectTarget, '/') ? 'https://'.$host.$redirectTarget : $redirectTarget;
            $nextUrl = $this->normalizer->normalize($absoluteTarget, true, []);

            return $this->resolveNormalized($nextUrl, $originalUrl, $visited, $hopCount + 1, true);
        }

        return new UnresolvedUrl($originalUrl, $this->pathClassifier->classify($path));
    }

    private function stripBasePath(string $path, string $basePath): string
    {
        $basePath = rtrim($basePath, '/');

        if ('' !== $basePath && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        return '' === $path ? '/' : $path;
    }
}
