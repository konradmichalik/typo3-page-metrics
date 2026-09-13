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

use TYPO3\CMS\Core\Site\Entity\Site;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function preg_match;

/**
 * RouteEnhancerRecordTables.
 *
 * Reads a site's route enhancer configuration and answers: if a matched
 * route carries an argument called `news`, which table is its value a uid
 * of? A persisted mapper cannot work without naming its `tableName`, and
 * the integrator maintains that because routing itself depends on it, so
 * the answer is already on disk and needs no configuration of our own.
 *
 * The mapping from an aspect to the argument it ends up in is TYPO3's own:
 * an aspect is keyed by the route path variable, `_arguments` renames that
 * variable to the plugin argument, and the enhancer nests it under its
 * plugin namespace. So the argument's leaf name is `_arguments[aspect]`,
 * falling back to the aspect name.
 *
 * Nothing here throws or queries. A hand-broken configuration, or one whose
 * enhancer comes from an extension that is no longer installed, contributes
 * no table rather than aborting an import run.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteEnhancerRecordTables
{
    /**
     * Table names are lowercase identifiers of at most 64 characters in
     * TYPO3 and in every database it supports. Checking that here is what
     * lets the value be stored and later queried without re-checking: it
     * comes from a configuration file, which is trusted but not validated.
     *
     * @var string
     */
    private const TABLE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * Every record table reachable from the enhancers that could have
     * matched on this page, keyed by route argument name. A name mapped to
     * more than one table is reported as such rather than silently picking
     * one, because the caller has to refuse an ambiguous match.
     *
     * @return array<string, list<string>>
     */
    public function forPage(Site $site, int $pageUid): array
    {
        $tables = [];

        foreach ($this->applicableEnhancers($site, $pageUid) as $enhancer) {
            foreach ($this->recordTablesOf($enhancer) as [$name, $table]) {
                $tables[$name] = $this->appendTable($tables[$name] ?? [], $table);
            }
        }

        return $tables;
    }

    /**
     * @return list<array<mixed>>
     */
    private function applicableEnhancers(Site $site, int $pageUid): array
    {
        // limitToPages is the router's own scoping, so an enhancer that
        // could not have produced this match must not lend it a table name
        // either.
        return array_values(array_filter(
            $this->nestedArrays($site->getConfiguration()['routeEnhancers'] ?? null),
            fn (array $enhancer): bool => $this->appliesToPage($enhancer, $pageUid),
        ));
    }

    /**
     * The record tables this enhancer's aspects name, each keyed by the
     * argument name a matched route will carry it under.
     *
     * @param array<mixed> $enhancer
     *
     * @return list<array{string, string}>
     */
    private function recordTablesOf(array $enhancer): array
    {
        $argumentNames = $this->argumentNames($enhancer);
        $tables = [];

        foreach ($this->toArray($enhancer['aspects'] ?? null) as $aspectName => $aspect) {
            $table = $this->recordTableOf($aspect);

            if (null !== $table) {
                $tables[] = [$argumentNames[(string) $aspectName] ?? (string) $aspectName, $table];
            }
        }

        return $tables;
    }

    /**
     * Only a persisted mapper names a table. A static mapper or a locale
     * modifier maps a segment to a value, not to a record, and drops out
     * here without having to be listed by name - which is also what lets a
     * third-party mapper participate.
     */
    private function recordTableOf(mixed $aspect): ?string
    {
        $table = $this->toArray($aspect)['tableName'] ?? null;

        if (!is_string($table) || 1 !== preg_match(self::TABLE_PATTERN, $table)) {
            return null;
        }

        return $table;
    }

    /**
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private function appendTable(array $tables, string $table): array
    {
        return in_array($table, $tables, true) ? $tables : [...$tables, $table];
    }

    /**
     * @param array<mixed> $enhancer
     *
     * @return array<string, string>
     */
    private function argumentNames(array $enhancer): array
    {
        $names = [];

        foreach ($this->argumentMap($enhancer) as $aspectName => $argumentName) {
            if (is_string($argumentName)) {
                $names[(string) $aspectName] = $this->leafName($argumentName);
            }
        }

        return $names;
    }

    /**
     * The `_arguments` renaming of the enhancer itself and of every route it
     * declares, in one map. An Extbase enhancer puts it per route, a Simple
     * or Plugin enhancer puts it on the enhancer.
     *
     * @param array<mixed> $enhancer
     *
     * @return array<mixed>
     */
    private function argumentMap(array $enhancer): array
    {
        $map = [];

        foreach ([$enhancer, ...$this->nestedArrays($enhancer['routes'] ?? null)] as $section) {
            $map = array_merge($map, $this->toArray($section['_arguments'] ?? null));
        }

        return $map;
    }

    /**
     * A nested target such as 'blog/author' becomes a nested argument, and
     * the leaf is what the flattened argument bag is keyed by.
     */
    private function leafName(string $argumentName): string
    {
        $segments = explode('/', $argumentName);

        return $segments[count($segments) - 1];
    }

    /**
     * @param array<mixed> $enhancer
     */
    private function appliesToPage(array $enhancer, int $pageUid): bool
    {
        $limitToPages = $this->toArray($enhancer['limitToPages'] ?? null);

        if ([] === $limitToPages) {
            return true;
        }

        return in_array($pageUid, array_map(intval(...), array_filter($limitToPages, is_scalar(...))), true);
    }

    /**
     * @return array<mixed>
     */
    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<array<mixed>>
     */
    private function nestedArrays(mixed $value): array
    {
        return array_values(array_filter($this->toArray($value), is_array(...)));
    }
}
