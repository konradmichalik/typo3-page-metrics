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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\RecordReference;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;

use function count;
use function is_array;
use function is_scalar;
use function is_string;
use function preg_match;

/**
 * RecordArgumentExtractor.
 *
 * Says which record a matched route is about. The router has already done
 * the expensive half: a persisted mapper looked the URL segment up in its
 * own table to decide the route matches at all, and PageArguments carries
 * the uid it found, e.g. tx_news_pi1[news] => 42. What that bag does not
 * say is which table the 42 belongs to, and RouteEnhancerRecordTables reads
 * that back out of the very configuration the router just ran.
 *
 * So this costs no query and no knowledge of news, products or events. It
 * is array work on two things that are already in memory.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RecordArgumentExtractor
{
    /** @var string */
    private const UID_PATTERN = '/^[1-9][0-9]*$/';

    public function __construct(
        private RouteEnhancerRecordTables $recordTables,
    ) {}

    public function extract(Site $site, PageArguments $pageArguments): ?RecordReference
    {
        $tablesByArgument = $this->recordTables->forPage($site, $pageArguments->getPageId());
        $found = null;

        foreach ($this->leafArguments($pageArguments->getRouteArguments()) as [$name, $value]) {
            $tables = $tablesByArgument[$name] ?? [];
            $uid = $this->recordUid($value);

            if ([] === $tables || null === $uid) {
                continue;
            }

            // Two enhancers claiming the same argument name for different
            // tables, or a route mapping two records at once (/{category}/
            // {article}): the URL does not say which one the page is about.
            // Attributing the numbers to the wrong record is worse than not
            // attributing them, because nothing downstream could tell.
            if (1 !== count($tables) || null !== $found) {
                return null;
            }

            $found = new RecordReference($tables[0], $uid);
        }

        return $found;
    }

    /**
     * Route arguments arrive nested under the plugin namespace they belong
     * to; an aspect is keyed by its leaf name, so that is what is compared.
     *
     * @param array<mixed> $arguments
     *
     * @return list<array{string, string}>
     */
    private function leafArguments(array $arguments): array
    {
        $leaves = [];

        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                $leaves = [...$leaves, ...$this->leafArguments($value)];
            } elseif (is_string($key) && is_scalar($value)) {
                $leaves[] = [$key, (string) $value];
            }
        }

        return $leaves;
    }

    /**
     * A mapper that could not resolve its segment leaves the raw segment in
     * place, and a route default may carry anything at all.
     */
    private function recordUid(string $value): ?int
    {
        if (1 !== preg_match(self::UID_PATTERN, $value)) {
            return null;
        }

        return (int) $value;
    }
}
