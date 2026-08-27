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

use function count;
use function is_scalar;

/**
 * Normalizer.
 *
 * First resolver stage: brings equivalent URLs to one canonical string
 * before anything else looks at them, so the in-memory resolution cache in
 * Resolver actually hits.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Normalizer
{
    /** @var array<string, int> */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * @param list<string> $keepQueryParams
     */
    public function normalize(string $url, bool $dropQueryString, array $keepQueryParams): string
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';

        if ('' !== $path && '/' !== $path && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $authority = $host;
        if (null !== $port && (self::DEFAULT_PORTS[$scheme] ?? null) !== $port) {
            $authority .= ':'.$port;
        }

        $queryString = $this->buildQueryString($query, $dropQueryString, $keepQueryParams);

        return $scheme.'://'.$authority.$path.('' !== $queryString ? '?'.$queryString : '');
    }

    /**
     * @param list<string> $keepQueryParams
     */
    private function buildQueryString(string $query, bool $dropQueryString, array $keepQueryParams): string
    {
        if ('' === $query) {
            return '';
        }

        if (!$dropQueryString) {
            return $query;
        }

        parse_str($query, $allParams);
        $kept = [];

        foreach ($keepQueryParams as $keepKey) {
            $value = $this->extractByDottedKey($allParams, $keepKey);
            if (null !== $value) {
                $kept[$keepKey] = $value;
            }
        }

        if ([] === $kept) {
            return '';
        }

        return http_build_query($kept);
    }

    /**
     * @param array<int|string, mixed> $allParams
     */
    private function extractByDottedKey(array $allParams, string $key): ?string
    {
        if (!str_contains($key, '[')) {
            return isset($allParams[$key]) && is_scalar($allParams[$key]) ? (string) $allParams[$key] : null;
        }

        preg_match('/^([^\[]+)\[([^\]]+)]$/', $key, $matches);
        if (3 !== count($matches)) {
            return null;
        }

        $value = $allParams[$matches[1]][$matches[2]] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
