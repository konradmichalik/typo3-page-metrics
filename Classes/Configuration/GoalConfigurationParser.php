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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricPolarity;
use KonradMichalik\Typo3PageMetrics\Exception\InvalidSiteConfigurationException;

use function array_column;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * GoalConfigurationParser.
 *
 * Validates the "pageMetrics.goals" list for SiteConfigurationReader, which
 * fails fast on a malformed value under an existing key. A collaborator
 * rather than another branch in the reader: a goal entry is a nested
 * structure with five fields of its own, and the reader's remaining checks
 * are flat one-liners.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class GoalConfigurationParser
{
    /**
     * A goal id is copied verbatim into the stored metric identifier
     * (matomo.goal.<id>.conversions), so anything that would make that
     * identifier ambiguous or unsearchable is rejected here rather than
     * written into the fact table.
     */
    /**
     * The only provider whose API reports a goal's revenue alongside its
     * conversions in the response this extension already asks for.
     */
    private const PROVIDER_WITH_REVENUE = 'matomo';

    private const ID_PATTERN = '/^[A-Za-z0-9_.-]+$/';

    /**
     * @param list<mixed> $goals
     *
     * @return list<GoalConfiguration>
     */
    public function parse(string $siteIdentifier, array $goals): array
    {
        $parsed = [];
        $seen = [];

        foreach ($goals as $entry) {
            $goal = $this->parseGoal($siteIdentifier, $entry);
            $key = $goal->provider.'|'.$goal->id;

            // Two entries for the same provider and id would write their
            // conversions into one metric identifier twice, so the import
            // would count every conversion as two.
            if (isset($seen[$key])) {
                throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.goals" declares goal "%s" for provider "%s" twice.', $siteIdentifier, $goal->id, $goal->provider), 1756000018);
            }

            $seen[$key] = true;
            $parsed[] = $goal;
        }

        return $parsed;
    }

    private function parseGoal(string $siteIdentifier, mixed $entry): GoalConfiguration
    {
        if (!is_array($entry)) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": every entry of "pageMetrics.goals" must be a mapping.', $siteIdentifier), 1756000012);
        }

        $provider = $entry['provider'] ?? null;
        if (!is_string($provider) || '' === $provider) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.goals" entries need a non-empty "provider".', $siteIdentifier), 1756000013);
        }

        $label = $entry['label'] ?? null;
        if (!is_string($label) || '' === $label) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.goals" entries need a non-empty "label".', $siteIdentifier), 1756000015);
        }

        $trackRevenue = $entry['revenue'] ?? false;
        if (!is_bool($trackRevenue)) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.goals.revenue" must be a boolean.', $siteIdentifier), 1756000017);
        }

        // Refused rather than ignored. GA4 reports an event's value
        // separately from its count, so there is nothing for this flag to
        // read, and a revenue tile that stayed at zero forever would look
        // like a site that earns nothing rather than like a setting that
        // does not apply. Fail fast, as everything else in this file does.
        if ($trackRevenue && self::PROVIDER_WITH_REVENUE !== $provider) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.goals.revenue" is only available for the "%s" provider, not for "%s".', $siteIdentifier, self::PROVIDER_WITH_REVENUE, $provider), 1756000019);
        }

        return new GoalConfiguration(
            $provider,
            $this->parseId($siteIdentifier, $entry['id'] ?? null),
            $label,
            $this->parsePolarity($siteIdentifier, $entry['polarity'] ?? null),
            $trackRevenue,
        );
    }

    private function parseId(string $siteIdentifier, mixed $id): string
    {
        // Matomo goal ids are written as plain YAML integers, GA4 key-event
        // names as strings, and both end up in the same field.
        $id = is_int($id) ? (string) $id : $id;

        if (!is_string($id) || 1 !== preg_match(self::ID_PATTERN, $id)) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.goals.id" must be an integer or a string of letters, digits, "_", "." and "-".', $siteIdentifier), 1756000014);
        }

        return $id;
    }

    private function parsePolarity(string $siteIdentifier, mixed $polarity): MetricPolarity
    {
        // Almost every goal is one where more of it is better, which is why
        // an unset polarity is not an error.
        if (null === $polarity) {
            return MetricPolarity::HigherIsBetter;
        }

        $parsed = is_string($polarity) ? MetricPolarity::tryFrom($polarity) : null;

        if (null === $parsed) {
            throw new InvalidSiteConfigurationException(sprintf('Site "%s": "pageMetrics.goals.polarity" must be one of "%s".', $siteIdentifier, implode('", "', array_column(MetricPolarity::cases(), 'value'))), 1756000016);
        }

        return $parsed;
    }
}
