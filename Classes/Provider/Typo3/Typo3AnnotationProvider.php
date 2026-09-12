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

namespace KonradMichalik\Typo3PageMetrics\Provider\Typo3;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Configuration\SiteConfigurationReader;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use KonradMichalik\Typo3PageMetrics\Provider\AnnotationProviderInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

use function sprintf;

/**
 * Typo3AnnotationProvider.
 *
 * Markers nobody has to maintain. Every other annotation source waits for a
 * person to write something down, which is why annotations are empty almost
 * everywhere, while TYPO3 has been recording the same events all along: a
 * page was edited, a redirect now points at it.
 *
 * Derived in the nightly run from sys_history rather than written by a
 * DataHandler hook, which is what makes five edits to one page on one day a
 * single marker instead of five.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class Typo3AnnotationProvider implements AnnotationProviderInterface
{
    private const IDENTIFIER = 'typo3';

    /**
     * How far back markers are derived, regardless of how long the site keeps
     * its metrics. No surface draws a marker older than 90 days (the longest
     * period the page tiles offer, and the window the dashboard widgets use),
     * so re-deriving the default 400 days of history every night would be
     * work nobody ever sees.
     */
    private const LOOKBACK_DAYS = 90;

    public function __construct(
        private SiteConfigurationReader $siteConfigurationReader,
        private PageChangeLookup $pageChangeLookup,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function supports(Site $site): bool
    {
        $configuration = $this->siteConfigurationReader->read($site);

        return $configuration->enabled && $configuration->automaticAnnotations;
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        foreach ($this->pageChangeLookup->changesFor($site, $this->clamp($range)) as $change) {
            yield new Annotation(
                $this->identify($change),
                $change->date,
                $this->describe($change),
                $change->category,
                // An automatic marker always falls on one day: it is derived
                // per day in the first place, and a run of edited days is a
                // run of events, not one event that lasted a week.
                null,
                $change->pageUid,
            );
        }
    }

    private function clamp(DateRange $range): DateRange
    {
        $earliest = new DateTimeImmutable(sprintf('today -%d days', self::LOOKBACK_DAYS));

        return $range->from >= $earliest ? $range : new DateRange($earliest, $range->until);
    }

    /**
     * Stable across runs so the nightly re-derivation updates yesterday's
     * marker instead of adding a second one: the same page changed on the
     * same day is the same event, whatever else happened since.
     */
    private function identify(DailyPageChange $change): string
    {
        return sprintf('%s-%d-%s', $change->category->value, $change->pageUid, $change->date->format('Ymd'));
    }

    /**
     * Deliberately says what happened and how often, never who did it:
     * sys_history names the backend user, and a marker that carried that
     * name would put an editor's identity on a chart.
     */
    private function describe(DailyPageChange $change): string
    {
        if (AnnotationCategory::Seo === $change->category) {
            return 1 === $change->changes
                ? 'Redirect created'
                : sprintf('%d redirects created', $change->changes);
        }

        return 1 === $change->changes
            ? 'Page updated'
            : sprintf('Page updated, %d changes', $change->changes);
    }
}
