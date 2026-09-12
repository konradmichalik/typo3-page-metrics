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

namespace KonradMichalik\Typo3PageMetrics\Provider\AccessLog;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Guard\PathHeuristicGuard;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\{CombinedLogParser, LogLine};
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\BotTaxonomy;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Provider\MetricProviderInterface;
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use TYPO3\CMS\Core\Site\Entity\Site;

use function count;
use function explode;
use function fclose;
use function fgets;
use function fopen;
use function glob;
use function gzclose;
use function gzeof;
use function gzgets;
use function gzopen;
use function implode;
use function in_array;
use function pathinfo;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strtolower;

/**
 * AccessLogMetricProvider.
 *
 * Reads the site's own Apache/Nginx "combined" access log(s) directly
 * (AccessLogConfiguration::$pathPattern, resolved via glob() so a rotated
 * set like "access.log", "access.log.1", "access.log-20261001.gz" is read
 * as one set) rather than calling an analytics API - no Matomo/GA4 needed,
 * at the cost of only ever offering page views and an approximated visit
 * count: a raw access log carries no session/client-side signal, so
 * "avg. time on page" and "bounce rate" (see MatomoMetricProvider,
 * Ga4MetricProvider) have nothing to be derived from here and are
 * deliberately not offered (see getRateMetricDefinitions()).
 *
 * "Visits" is a same-day distinct (IP address, user agent) count per page,
 * not a true session count - the closest approximation a stateless log
 * line allows, and the reason its own MetricDefinition description calls
 * it out as such.
 *
 * Every line in range is also classified via BotTaxonomy. A classified
 * line never counts toward pageviews/visits (it is bot traffic, not a
 * human page view) and instead contributes to that bucket's own
 * bot.<bucket> metric - and, unlike the human path, WITHOUT the GET/2xx/
 * static-asset/backend-path filtering pageviews/visits apply: a bot
 * hitting a dead link, a redirect, or a backend probe is exactly the
 * traffic this diagnostic exists to surface (see the merge design spec's
 * §4.3 "Redirect-Anteil entsteht kostenlos"), not traffic to discard
 * before it can be measured. BotTaxonomy's own static-asset exclusion
 * still applies (real CSS/JS/image hits are never classified as any
 * bucket), so no separate path filter is needed on this side either.
 * Agent-level detail (which specific bot, and whether its path pattern
 * looked spoofed) does not fit UrlMetric's shape and is instead handed to
 * BotAgentDetailCollector, flushed by ImportCommand once this provider's
 * fetch() has been fully consumed.
 *
 * BotTaxonomy is a curated allowlist of named agents, so an unnamed vendor
 * crawler, a generic curl/wget script or an empty user agent is never
 * classified into a bucket - and must therefore not silently fall through
 * into the human numbers either. GENERIC_BOT_SIGNATURES catches exactly
 * that residue on the human branch alone (see isGenericBotNoise()): such a
 * line counts toward neither pageviews/visits nor any bot.<bucket> metric,
 * because there is no bucket for a bot nobody has a name for.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class AccessLogMetricProvider implements MetricProviderInterface
{
    private const IDENTIFIER = 'accesslog';

    /**
     * Checked against the request path alone (query string stripped first,
     * see isRelevantPath()) - a log covering the whole site inevitably also
     * carries every CSS/JS/image/font hit, which would otherwise swamp real
     * page views by orders of magnitude. Applies only to the human-metrics
     * path; see the class docblock for why bot classification does not use
     * this filter.
     */
    private const STATIC_ASSET_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp',
        'ico', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'mp4', 'webm', 'pdf',
        'zip', 'xml', 'json', 'txt',
    ];

    /**
     * A best-effort substring heuristic for the crawlers, monitors and
     * generic HTTP clients BotTaxonomy's named allowlist cannot name, kept
     * deliberately coarse: its only job is to keep them out of the human
     * page-view counts they would otherwise dominate, not to attribute them
     * to anyone. Applies only to lines BotTaxonomy left unclassified.
     */
    private const GENERIC_BOT_SIGNATURES = [
        'bot', 'crawl', 'spider', 'slurp', 'archiver', 'facebookexternalhit',
        'curl', 'wget', 'python-requests', 'python-urllib', 'go-http-client',
        'okhttp', 'axios', 'libwww-perl', 'scrapy', 'headlesschrome',
        'phantomjs', 'monitor', 'pingdom', 'uptimerobot', 'ahrefs', 'semrush',
        'mj12', 'petalbot', 'dotbot', 'seznambot',
    ];

    // Built once per instance rather than on every isGenericBotNoise() call:
    // that runs once per parsed log line, easily hundreds of thousands of
    // times for a busy site's daily log, and the signature list never
    // changes at runtime.
    private string $genericBotPattern;

    public function __construct(
        private AccessLogConfigurationResolver $configurationResolver,
        private CombinedLogParser $lineParser,
        private BotTaxonomy $taxonomy,
        private PathHeuristicGuard $guard,
        private BotAgentDetailCollector $agentDetailCollector,
    ) {
        $this->genericBotPattern = sprintf('/%s/i', implode('|', self::GENERIC_BOT_SIGNATURES));
    }

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function supports(Site $site): bool
    {
        return null !== $this->configurationResolver->resolve($site);
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition('accesslog.pageviews', 'Page views', 'Successful (2xx) GET requests for this page in the selected period, parsed from the web server access log.', 'tabler-eye-search', MetricUnit::Count, 10, '#116329'),
            new MetricDefinition('accesslog.visits', 'Visits', 'Distinct IP address/user agent pairs that requested this page on the same day - an approximation, not a true session count.', 'tabler-chart-dots', MetricUnit::Count, 20, '#57606a'),
            new MetricDefinition('bot.ai_chatbot', 'AI chatbots', 'Requests from AI chatbots fetching this page live in response to a specific user request.', 'tabler-robot', MetricUnit::Count, 30, '#4c6ef5'),
            new MetricDefinition('bot.ai_crawler', 'AI crawlers', 'Requests from AI crawlers collecting content for model training or an AI search index.', 'tabler-spider', MetricUnit::Count, 40, '#ae3ec9'),
            new MetricDefinition('bot.search', 'Search indexers', 'Requests from real search-engine indexers building their search index.', 'tabler-world-search', MetricUnit::Count, 50, '#0ca678'),
            new MetricDefinition('bot.social', 'Social/link previews', 'Requests from social platforms generating link previews for shared URLs.', 'tabler-share', MetricUnit::Count, 60, '#f06595'),
            new MetricDefinition('bot.tool', 'SEO tools & scripts', 'Requests from SEO tools, monitoring services and generic HTTP clients.', 'tabler-tool', MetricUnit::Count, 70, '#f59f00'),
        ];
    }

    public function getRateMetricDefinitions(): array
    {
        return [];
    }

    public function getExternalLink(Site $site, string $pageUrl, DateRange $range): ?string
    {
        // A raw access log has no external UI of its own to deep-link into.
        return null;
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        $configuration = $this->configurationResolver->resolve($site);

        if (null === $configuration) {
            return;
        }

        $allowedDates = [];
        foreach ($range->days() as $day) {
            $allowedDates[$day->format('Y-m-d')] = true;
        }

        $base = $site->getBase();
        $baseUrl = $base->getScheme().'://'.$base->getAuthority();

        /** @var array<string, array<string, int>> $pageviews date => path => hit count */
        $pageviews = [];
        /** @var array<string, array<string, array<string, true>>> $visitors date => path => "ip|userAgent" set */
        $visitors = [];
        /** @var array<string, array<string, array<string, int>>> $botHits date => bucket value => url => hit count */
        $botHits = [];

        foreach ($this->lines($configuration->pathPattern) as $rawLine) {
            $line = $this->lineParser->parseLine($rawLine);

            if (null === $line) {
                continue;
            }

            $date = $line->timestamp->format('Y-m-d');

            if (!isset($allowedDates[$date])) {
                continue;
            }

            $classification = $this->taxonomy->classify($line);
            $url = $baseUrl.$line->target;

            if (null !== $classification) {
                $botHits[$date][$classification->bucket->value][$url] = ($botHits[$date][$classification->bucket->value][$url] ?? 0) + 1;
                $this->agentDetailCollector->add($line->timestamp, $url, $classification->bucket, $classification->agent, $this->guard->isSuspicious($line));

                continue;
            }

            if (!$this->countsAsHumanPageview($line)) {
                continue;
            }

            $pageviews[$date][$line->target] = ($pageviews[$date][$line->target] ?? 0) + 1;
            $visitors[$date][$line->target][$line->ip.'|'.$line->userAgent] = true;
        }

        foreach ($pageviews as $date => $paths) {
            $day = new DateTimeImmutable($date);

            foreach ($paths as $path => $hits) {
                $url = $baseUrl.$path;
                yield new UrlMetric($url, $day, 'accesslog.pageviews', $hits);
                yield new UrlMetric($url, $day, 'accesslog.visits', count($visitors[$date][$path] ?? []));
            }
        }

        foreach ($botHits as $date => $byBucket) {
            $day = new DateTimeImmutable($date);

            foreach ($byBucket as $bucketValue => $urls) {
                foreach ($urls as $url => $hits) {
                    yield new UrlMetric($url, $day, 'bot.'.$bucketValue, $hits);
                }
            }
        }
    }

    /**
     * Only reached for a line BotTaxonomy left unclassified, i.e. one that
     * has already been ruled out as named bot traffic. Everything it
     * rejects here is discarded outright: it contributes to no bot bucket
     * (there is none for it) and to no human metric either.
     */
    private function countsAsHumanPageview(LogLine $line): bool
    {
        return 'GET' === $line->method
            && $this->wasSuccessful($line->status)
            && $this->isRelevantPath($line->target)
            && !$this->isGenericBotNoise($line->userAgent);
    }

    /**
     * An empty user agent counts as a match: browsers always send one, so
     * its absence is itself a signal of a script rather than a person.
     */
    private function isGenericBotNoise(string $userAgent): bool
    {
        if ('' === $userAgent) {
            return true;
        }

        return 1 === preg_match($this->genericBotPattern, $userAgent);
    }

    /**
     * 2xx, plus 304 Not Modified: a conditional GET that hits the browser's
     * own cache still means the page was viewed, it just didn't need a
     * fresh body - excluding it would undercount every repeat visit on a
     * site with normal cache-revalidation headers (ETag/Last-Modified).
     * Every other 3xx is excluded on purpose: a redirect's own eventual 200
     * on the target page is what should count, not the hop that got it
     * there. Applies only to the human-metrics path.
     */
    private function wasSuccessful(int $statusCode): bool
    {
        return ($statusCode >= 200 && $statusCode <= 299) || 304 === $statusCode;
    }

    private function isRelevantPath(string $pathWithQuery): bool
    {
        $path = explode('?', $pathWithQuery, 2)[0];

        if (str_starts_with($path, '/typo3/') || str_starts_with($path, '/typo3conf/')) {
            return false;
        }

        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return '' === $extension || !in_array($extension, self::STATIC_ASSET_EXTENSIONS, true);
    }

    /**
     * @return iterable<string>
     */
    private function lines(string $pathPattern): iterable
    {
        foreach (glob($pathPattern) ?: [] as $file) {
            yield from str_ends_with($file, '.gz') ? $this->gzipLines($file) : $this->plainLines($file);
        }
    }

    /**
     * @return iterable<string>
     */
    private function plainLines(string $file): iterable
    {
        $handle = fopen($file, 'r');

        if (false === $handle) {
            return;
        }

        try {
            while (false !== ($line = fgets($handle))) {
                yield $line;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return iterable<string>
     */
    private function gzipLines(string $file): iterable
    {
        $handle = gzopen($file, 'rb');

        if (false === $handle) {
            return;
        }

        try {
            while (!gzeof($handle)) {
                $line = gzgets($handle);

                if (false !== $line) {
                    yield $line;
                }
            }
        } finally {
            gzclose($handle);
        }
    }
}
