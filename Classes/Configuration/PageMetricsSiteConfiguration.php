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

namespace KonradMichalik\Typo3PageMetrics\Configuration;

/**
 * PageMetricsSiteConfiguration.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageMetricsSiteConfiguration
{
    /**
     * @param array<string, array<string, mixed>> $providers
     * @param list<string>                        $keepQueryParams
     * @param list<string>                        $additionalHosts
     * @param int|null                            $annotationStoragePid the page manual annotations are created on,
     *                                                                  null when the site names none
     * @param bool                                $automaticAnnotations whether the markers TYPO3 can derive on its own
     *                                                                  (a page edited, a redirect created) are written
     *                                                                  during the import run
     * @param list<GoalConfiguration>             $goals
     */
    public function __construct(
        public bool $enabled,
        public array $providers,
        public bool $dropQueryString,
        public array $keepQueryParams,
        public int $retentionDays,
        public array $additionalHosts,
        public ?int $annotationStoragePid,
        public bool $automaticAnnotations,
        public array $goals,
    ) {}

    /**
     * The goals one provider owns. Goals are declared in a single list for
     * the whole site rather than inside each provider's own subtree, because
     * what an operator configures is "what counts as success here", and the
     * provider is only which account that number is read from.
     *
     * @return list<GoalConfiguration>
     */
    public function goalsFor(string $providerIdentifier): array
    {
        return array_values(array_filter(
            $this->goals,
            static fn (GoalConfiguration $goal): bool => $goal->provider === $providerIdentifier,
        ));
    }
}
