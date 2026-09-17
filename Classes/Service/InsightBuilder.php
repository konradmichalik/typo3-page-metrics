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

namespace KonradMichalik\Typo3PageMetrics\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\{MetricGroup, TrendDirection, TrendSentiment};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, Insight, MetricDefinition, MetricSignificance, MetricTrend, PageTrend, RateMetricDefinition};
use KonradMichalik\Typo3PageMetrics\Persistence\{DailyMetricsRepository, SitePageUidRepository};
use KonradMichalik\Typo3PageMetrics\Provider\{MetricDefinitionCollector, MetricProviderInterface};
use TYPO3\CMS\Core\Site\Entity\Site;

use function abs;
use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_sum;
use function array_unique;
use function array_values;
use function max;
use function min;
use function sprintf;
use function usort;

/**
 * InsightBuilder.
 *
 * Turns the movements the trend ranking already knows about into the
 * handful of sentences worth stating, for the "what changed" widget.
 *
 * Four fixed slots per site, and no others:
 *
 * 1. the site's own total on its primary metric,
 * 2. the site's total on the single most significant other metric,
 * 3. the page that rose most,
 * 4. the page that fell most.
 *
 * Every slot can stay empty, and all four staying empty is the ordinary
 * outcome of a quiet month rather than a failure. Nothing here manufactures
 * a statement to fill a card: a movement is stated only once it has cleared
 * the metric's own MetricSignificance, which is the same double threshold
 * the trend widget filters by.
 *
 * The cards of every site are pooled into one list, ranked together and cut
 * to four - the cap belongs to the widget, not to each site, because four
 * cards spread over six sites is not four cards about anything.
 *
 * Ranking across two kinds of statement needs one number that means the
 * same thing for both, and "how much it moved" is not that number: views
 * and percentage points do not compare, and neither do a whole site's
 * totals and one page's. What does compare is how many times over its own
 * threshold each movement went (see score()). Above that sits one hard
 * rule: every site-level card precedes every page-level one. A page that
 * rose 46% inside a site that rose 40% rose with the site, and no reader
 * can tell that apart from a page that rose on its own until the site's
 * own line has been read.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class InsightBuilder
{
    private const MAX_CARDS = 4;

    /**
     * The default language, as every dashboard widget uses: a widget has no
     * language picker, and a site's default language is what its totals are
     * read as everywhere else on the dashboard.
     */
    private const LANGUAGE_ID = 0;

    /**
     * @param iterable<MetricProviderInterface> $providers
     */
    public function __construct(
        private SitePageUidRepository $pageUidRepository,
        private DailyMetricsRepository $dailyMetricsRepository,
        private RateMetricReader $rateMetricReader,
        private ImportCoverage $importCoverage,
        private MetricDefinitionCollector $metricDefinitionCollector,
        private MetricValueFormatter $valueFormatter,
        private iterable $providers,
    ) {}

    /**
     * @param array<Site> $sites
     *
     * @return list<Insight> at most four, most significant first, empty when nothing moved
     */
    public function build(array $sites, int $periodDays): array
    {
        $insights = [];

        foreach ($sites as $site) {
            foreach ($this->forSite($site, $periodDays) as $insight) {
                $insights[] = $insight;
            }
        }

        usort($insights, static function (Insight $a, Insight $b): int {
            // The frame before the detail, whatever the two scores are.
            if ($a->isSiteLevel() !== $b->isSiteLevel()) {
                return $a->isSiteLevel() ? -1 : 1;
            }

            return $b->score <=> $a->score;
        });

        return array_slice($insights, 0, self::MAX_CARDS);
    }

    /**
     * Whether any of $sites has a metric provider at all, which is what
     * tells the widget's two empty states apart: "nothing moved" is a
     * finding about a site somebody is collecting numbers for, while on an
     * installation that has configured none it would be a claim about data
     * nobody gathered.
     *
     * Reads site configuration only, no queries: it answers a question
     * about setup, not about a period.
     *
     * @param array<Site> $sites
     */
    public function hasSpeakableSite(array $sites): bool
    {
        foreach ($sites as $site) {
            if ([] !== $this->metricDefinitionCollector->supporting($this->providers, $site)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Insight>
     */
    private function forSite(Site $site, int $periodDays): array
    {
        // The site's primary metric, asked of every provider the site has
        // rather than of whichever one the container happens to hand over
        // first (see #88). The order is alphabetical by service id, so a
        // site pairing Matomo with an access log pared down to the bot
        // buckets is asked about the access log first, and a widget reading
        // only that one goes silent about a site with plenty to say.
        $primary = $this->metricDefinitionCollector->primaryFor($this->providers, $site);

        if (null === $primary) {
            return [];
        }

        // The rest of a card's frame is the primary metric's own provider's:
        // import coverage is kept per provider, and the second slot may only
        // offer metrics from the same source, or two cards of one site would
        // be measured against two different windows.
        $provider = $this->metricDefinitionCollector->providerOf($this->providers, $site, $primary->identifier);

        if (null === $provider) {
            return [];
        }

        [$current, $previous] = $this->computeRanges($periodDays);

        if (!$this->claimable($provider, $site, $current, $previous)) {
            return [];
        }

        $pageUids = $this->pageUidRepository->descendantsOf($site->getRootPageId());

        if ([] === $pageUids) {
            return [];
        }

        // Rates belong in the second slot as much as counts do: "bounce rate
        // up six points" is the kind of line an editor acts on, and it is
        // the reason the ranking had to become unit-free in the first place.
        $others = [
            ...array_values(array_filter(
                $this->audienceCountDefinitions($provider, $site),
                static fn (MetricDefinition $definition): bool => $definition->identifier !== $primary->identifier,
            )),
            ...$provider->getRateMetricDefinitions(),
        ];

        return array_values(array_filter([
            $this->siteInsight($site, $primary, $pageUids, $current, $previous, $periodDays),
            $this->bestOtherSiteInsight($site, $others, $pageUids, $current, $previous, $periodDays),
            ...$this->pageInsights($site, $primary, $pageUids, $current, $previous),
        ]));
    }

    /**
     * Whether this site may be spoken for at all.
     *
     * Both periods have to lie inside the imported window (see
     * ImportCoverage::covers()), minus the current period's last day: the
     * import runs for completed days, so no surface of this extension ever
     * holds today's rows and demanding them would leave the widget
     * permanently silent. Any earlier day missing is a scheduler that has
     * stopped, and the hole it leaves in the current period's total would
     * be stated here as a fall that never happened - which is the worst
     * output a widget whose only job is to make claims can produce.
     */
    private function claimable(MetricProviderInterface $provider, Site $site, DateRange $current, DateRange $previous): bool
    {
        $coverage = $this->importCoverage->forProvider($provider->getIdentifier(), $site->getIdentifier());
        $claimable = new DateRange($current->from, $current->until->modify('-1 day'));

        return $this->importCoverage->covers($coverage, $previous)
            && $this->importCoverage->covers($coverage, $claimable);
    }

    /**
     * @param list<MetricDefinition|RateMetricDefinition> $others
     * @param list<int>                                   $pageUids
     */
    private function bestOtherSiteInsight(Site $site, array $others, array $pageUids, DateRange $current, DateRange $previous, int $periodDays): ?Insight
    {
        $best = null;

        foreach ($others as $metric) {
            $insight = $this->siteInsight($site, $metric, $pageUids, $current, $previous, $periodDays);

            if (null !== $insight && (null === $best || $insight->score > $best->score)) {
                $best = $insight;
            }
        }

        return $best;
    }

    /**
     * @param list<int> $pageUids
     */
    private function siteInsight(Site $site, MetricDefinition|RateMetricDefinition $metric, array $pageUids, DateRange $current, DateRange $previous, int $periodDays): ?Insight
    {
        $significance = $metric->significance ?? MetricSignificance::default();
        $value = $this->siteTotal($metric, $pageUids, $current);
        $previousValue = $this->siteTotal($metric, $pageUids, $previous);

        if (!$significance->clears($value, $previousValue)) {
            return null;
        }

        $trend = MetricTrend::between($value, $previousValue);

        // A provider is free to declare a threshold of nothing at all, and a
        // threshold of nothing is cleared by an unchanged value. "Page views
        // are up by nothing" is not a sentence anybody needs.
        if (null === $trend->deltaPercent || TrendDirection::Flat === $trend->direction) {
            return null;
        }

        return new Insight(
            $site->getIdentifier(),
            TrendDirection::Down === $trend->direction ? Insight::SENTENCE_SITE_DOWN : Insight::SENTENCE_SITE_UP,
            [$metric->label, $periodDays, $this->valueFormatter->format($value, $metric->unit), abs($trend->deltaPercent)],
            TrendSentiment::of($trend->direction, $metric->polarity),
            $this->score($value, $previousValue, $significance),
        );
    }

    /**
     * The one page that rose most and the one that fell most, each judged by
     * PageTrend::weight() so the movement's size and the volume behind it
     * both count.
     *
     * The sums are read here rather than taken from
     * PageTrendCalculator::rankedByTrend(): that method answers with
     * TopPage, which carries the percentage but not the previous value, and
     * score() needs the raw pair. Recovering it per card would mean four
     * more queries for numbers this method already has in hand.
     *
     * @param list<int> $pageUids
     *
     * @return list<Insight>
     */
    private function pageInsights(Site $site, MetricDefinition $metric, array $pageUids, DateRange $current, DateRange $previous): array
    {
        $significance = $metric->significance ?? MetricSignificance::default();
        $currentSums = $this->dailyMetricsRepository->sumsByPage($pageUids, self::LANGUAGE_ID, $metric->identifier, $current);
        $previousSums = $this->dailyMetricsRepository->sumsByPage($pageUids, self::LANGUAGE_ID, $metric->identifier, $previous);

        /** @var array<string, PageTrend> $best */
        $best = [];

        foreach (array_unique([...array_keys($currentSums), ...array_keys($previousSums)]) as $pageUid) {
            $value = $currentSums[$pageUid] ?? 0;
            $previousValue = $previousSums[$pageUid] ?? 0;

            if (!$significance->clears($value, $previousValue)) {
                continue;
            }

            $trend = MetricTrend::between($value, $previousValue);

            if (null === $trend->deltaPercent || TrendDirection::Flat === $trend->direction) {
                continue;
            }

            // The count is its own volume: a page's traffic is both what
            // moved and the scale it moved at (see PageTrendCalculator).
            $candidate = new PageTrend($pageUid, $value, $previousValue, max($value, $previousValue), $trend->deltaPercent, $trend->direction);
            $incumbent = $best[$trend->direction->value] ?? null;

            if (null === $incumbent || $candidate->weight() > $incumbent->weight()) {
                $best[$trend->direction->value] = $candidate;
            }
        }

        if ([] === $best) {
            return [];
        }

        $titles = $this->pageUidRepository->titlesFor(array_values(array_map(static fn (PageTrend $trend): int => $trend->pageUid, $best)));

        $insights = [];

        foreach ($best as $trend) {
            $insights[] = new Insight(
                $site->getIdentifier(),
                TrendDirection::Down === $trend->direction ? Insight::SENTENCE_PAGE_FALL : Insight::SENTENCE_PAGE_RISE,
                [
                    $metric->label,
                    $titles[$trend->pageUid] ?? sprintf('#%d', $trend->pageUid),
                    abs($trend->deltaPercent ?? 0),
                    $this->valueFormatter->format($trend->value, $metric->unit),
                ],
                TrendSentiment::of($trend->direction, $metric->polarity),
                $this->score($trend->value, $trend->previousValue, $significance),
            );
        }

        return $insights;
    }

    /**
     * How many times over its own MetricSignificance a movement went.
     *
     * The one number in this class that is not in any metric's unit, which
     * is the whole point: a bounce rate that swung forty percentage points
     * and a page-views total that gained three hundred views cannot be
     * compared as movements, but "thirteen times its own floor" and "three
     * times its own floor" can.
     *
     * The weaker of the two halves wins, because a movement is exactly as
     * convincing as its weakest claim: one that cleared the relative
     * threshold twenty times over but the absolute one only just is a small
     * page doubling, and that is what the pairing exists to hold down.
     */
    private function score(int $value, int $previousValue, MetricSignificance $significance): float
    {
        $change = abs($value - $previousValue);
        $multiples = [];

        if ($significance->relative > 0.0 && 0 !== $previousValue) {
            $multiples[] = ($change / abs($previousValue)) / $significance->relative;
        }

        if ($significance->absolute > 0) {
            $multiples[] = $change / $significance->absolute;
        }

        // A metric whose provider set no threshold at all cleared nothing in
        // particular. Those all score alike, and PHP's stable sort leaves
        // them in declaration order behind everything that did clear one.
        return [] === $multiples ? 1.0 : min($multiples);
    }

    /**
     * @param list<int> $pageUids
     */
    private function siteTotal(MetricDefinition|RateMetricDefinition $metric, array $pageUids, DateRange $range): int
    {
        if ($metric instanceof MetricDefinition) {
            return array_sum($this->dailyMetricsRepository->sumsByPage($pageUids, self::LANGUAGE_ID, $metric->identifier, $range));
        }

        // A rate is not summable across pages any more than across days, so
        // it is the summed parts that divide, never the per-page ratios.
        return $this->rateMetricReader->forSite($pageUids, self::LANGUAGE_ID, $metric, $range)->ratio;
    }

    /**
     * Audience only, lowest sortOrder first. How often a crawler fetched a
     * page is a true statement and a real movement, and it is not what an
     * editor opened the dashboard to read (see MetricGroup).
     *
     * @return list<MetricDefinition>
     */
    private function audienceCountDefinitions(MetricProviderInterface $provider, Site $site): array
    {
        // The same set MetricDefinitionCollector::primaryFor() leads with,
        // and equal to it only because MetricGroup has exactly two cases:
        // forSite() already returns counts alone, so filtering to Audience is
        // filtering out Machine. A third group would make these two quietly
        // disagree, and this widget would start speaking for a metric no
        // other surface calls primary. Kept separate rather than folded in
        // because this one needs the whole ordered list, not only its head:
        // the second card is array_slice($counts, 1).

        $definitions = array_values(array_filter(
            $this->metricDefinitionCollector->forSite($provider, $site),
            static fn (MetricDefinition $definition): bool => MetricGroup::Audience === $definition->group,
        ));

        usort($definitions, static fn (MetricDefinition $a, MetricDefinition $b): int => $a->sortOrder <=> $b->sortOrder);

        return $definitions;
    }

    /**
     * The same two windows every other surface compares: the last
     * $periodDays ending today, against the $periodDays immediately before
     * them (see SiteMetricsOverviewBuilder::computeRanges()). Stated here
     * rather than shared, because a widget that silently followed another
     * class's idea of "the previous period" would be the hardest kind of
     * drift to notice in a sentence.
     *
     * @return array{0: DateRange, 1: DateRange}
     */
    private function computeRanges(int $periodDays): array
    {
        $today = new DateTimeImmutable('today');
        $current = new DateRange($today->modify(sprintf('-%d days', $periodDays - 1)), $today);
        $previousUntil = $current->from->modify('-1 day');
        $previous = new DateRange($previousUntil->modify(sprintf('-%d days', $periodDays - 1)), $previousUntil);

        return [$current, $previous];
    }
}
