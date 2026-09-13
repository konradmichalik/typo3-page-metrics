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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricGroup, MetricUnit};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\MetricDefinition;
use TYPO3\CMS\Core\Site\Entity\Site;

use function in_array;
use function usort;

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
 * And, for the fourth time, which one metric of those a site leads with (see
 * primaryFor()): three surfaces had each written their own rule for that and
 * charted different metrics for one site.
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
     * The site's primary metric: the first thing it counts, by sortOrder,
     * across every provider it has.
     *
     * One site has one of these and every surface names the same one, which
     * is the whole point of the method (see #88). A chart, a ranking and a
     * decay verdict ask slightly different questions, but a reader who has
     * to hold three answers apart to understand why two views of one page
     * disagree is worse off than one who knows a single term.
     *
     * Counted, so never a rate: a chart whose area is a percentage says
     * nothing, and a decay rule on a metric where falling can be the good
     * news files improvements as neglect. Audience, so never machine
     * traffic: every surface that leads with this metric is asking how the
     * page does with people.
     *
     * Null where the site counts nothing at all, which the per-provider
     * `metrics` allow list and a rate-only provider both make reachable. It
     * is an answer rather than a failure, and callers treat it exactly as
     * they treat a site with no provider.
     *
     * @param iterable<MetricProviderInterface> $providers
     */
    public function primaryFor(iterable $providers, Site $site): ?MetricDefinition
    {
        $candidates = [];

        foreach ($this->supporting($providers, $site) as $provider) {
            foreach ($this->forSite($provider, $site) as $definition) {
                if (MetricUnit::Count === $definition->unit && MetricGroup::Machine !== $definition->group) {
                    $candidates[] = $definition;
                }
            }
        }

        // Stable, so two providers declaring the same sortOrder are settled
        // by the order the container tagged them, which is the order every
        // other list of this site's metrics comes out in.
        usort($candidates, static fn (MetricDefinition $a, MetricDefinition $b): int => $a->sortOrder <=> $b->sortOrder);

        return $candidates[0] ?? null;
    }

    /**
     * Which of the site's providers offers $identifier, for a caller that
     * has to mask a series against the imported window: coverage is recorded
     * per provider (see ImportCoverage), and the provider a metric came from
     * is the only one whose window says anything about it.
     *
     * Null for a metric this site does not have, which is the same answer
     * primaryFor() gives about the metric itself.
     *
     * @param iterable<MetricProviderInterface> $providers
     */
    public function providerOf(iterable $providers, Site $site, string $identifier): ?MetricProviderInterface
    {
        foreach ($this->supporting($providers, $site) as $provider) {
            foreach ($this->forSite($provider, $site) as $definition) {
                if ($definition->identifier === $identifier) {
                    return $provider;
                }
            }
        }

        return null;
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
