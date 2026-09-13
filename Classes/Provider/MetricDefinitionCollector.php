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

namespace KonradMichalik\Typo3PageMetrics\Provider;

use KonradMichalik\Typo3PageMetrics\Configuration\SiteConfigurationReader;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricDefinition;
use TYPO3\CMS\Core\Site\Entity\Site;

use function in_array;

/**
 * MetricDefinitionCollector.
 *
 * What one provider offers for one site: its static definitions plus, where
 * it implements SiteAwareMetricProviderInterface, the ones that only exist
 * because this site configured them. Every consumer asks this rather than
 * the provider directly, so "does this provider have per-site metrics" is
 * decided in one place instead of at each of the five call sites.
 *
 * And which providers answer for a site at all, for the same reason: that
 * loop had been written out at seven call sites across five classes, and two
 * of them were written independently in the same afternoon because there was
 * nothing to reuse.
 *
 * It is also where a site's own "metrics" list is applied, for the third
 * time for the same reason: a consumer asks what this site has and gets an
 * answer that already accounts for what the site asked for. None of the five
 * knows the option exists.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MetricDefinitionCollector
{
    public function __construct(
        private SiteConfigurationReader $siteConfigurationReader,
    ) {}

    /**
     * The providers configured for this site, in the order the container
     * tagged them. Empty where the site opted into none, which is a
     * site-configuration answer and not an empty period: a caller that shows
     * numbers has to tell those apart.
     *
     * @param iterable<MetricProviderInterface> $providers
     *
     * @return list<MetricProviderInterface>
     */
    public function supporting(iterable $providers, Site $site): array
    {
        $supporting = [];

        foreach ($providers as $provider) {
            if ($provider->supports($site)) {
                $supporting[] = $provider;
            }
        }

        return $supporting;
    }

    /**
     * @return list<MetricDefinition>
     */
    public function forSite(MetricProviderInterface $provider, Site $site): array
    {
        $definitions = $provider instanceof SiteAwareMetricProviderInterface
            ? [...$provider->getMetricDefinitions(), ...$provider->getSiteMetricDefinitions($site)]
            : $provider->getMetricDefinitions();

        return $this->allowed($definitions, $provider, $site);
    }

    /**
     * A site naming no metrics for this provider gets all of them, which is
     * every site configured before the option existed.
     *
     * An entry is matched against the identifier and against the group, so
     * "machine" stands for every machine metric the provider offers, now and
     * after it adds one. That turns the case this exists for (Matomo for the
     * audience numbers, the access log only for the bots it can see and
     * Matomo cannot) into a list of one entry that never goes stale, instead
     * of five identifiers somebody has to keep up to date.
     *
     * Filtering the definitions rather than the stored values: the rows stay,
     * so widening the list shows the history that was collected all along.
     *
     * @param list<MetricDefinition> $definitions
     *
     * @return list<MetricDefinition>
     */
    private function allowed(array $definitions, MetricProviderInterface $provider, Site $site): array
    {
        $allowed = $this->siteConfigurationReader->read($site)->metricFilterFor($provider->getIdentifier());

        if (null === $allowed) {
            return $definitions;
        }

        return array_values(array_filter(
            $definitions,
            static fn (MetricDefinition $definition): bool => in_array($definition->identifier, $allowed, true)
                || in_array($definition->group->value, $allowed, true),
        ));
    }
}
