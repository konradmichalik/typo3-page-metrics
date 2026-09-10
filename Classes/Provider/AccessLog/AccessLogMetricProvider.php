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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Provider\MetricProviderInterface;
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
use function in_array;
use function pathinfo;
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
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class AccessLogMetricProvider implements MetricProviderInterface
{
    private const IDENTIFIER = 'accesslog';

    /**
     * Checked against the request path alone (query string stripped first,
     * see isRelevantPath()) - a log covering the whole site inevitably also
     * carries every CSS/JS/image/font hit, which would otherwise swamp real
     * page views by orders of magnitude.
     */
    private const STATIC_ASSET_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp',
        'ico', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'mp4', 'webm', 'pdf',
        'zip', 'xml', 'json', 'txt',
    ];

    public function __construct(
        private AccessLogConfigurationResolver $configurationResolver,
        private AccessLogLineParser $lineParser,
        private BotFilter $botFilter,
    ) {}

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

        foreach ($this->lines($configuration->pathPattern) as $line) {
            $entry = $this->lineParser->parse($line);

            if (null === $entry || 'GET' !== $entry->method || !$this->wasSuccessful($entry->statusCode)) {
                continue;
            }

            $date = $entry->date->format('Y-m-d');

            if (!isset($allowedDates[$date]) || !$this->isRelevantPath($entry->path) || $this->botFilter->isBot($entry->userAgent)) {
                continue;
            }

            $pageviews[$date][$entry->path] = ($pageviews[$date][$entry->path] ?? 0) + 1;
            $visitors[$date][$entry->path][$entry->ipAddress.'|'.$entry->userAgent] = true;
        }

        foreach ($pageviews as $date => $paths) {
            $day = new DateTimeImmutable($date);

            foreach ($paths as $path => $hits) {
                $url = $baseUrl.$path;
                yield new UrlMetric($url, $day, 'accesslog.pageviews', $hits);
                yield new UrlMetric($url, $day, 'accesslog.visits', count($visitors[$date][$path] ?? []));
            }
        }
    }

    /**
     * 2xx, plus 304 Not Modified: a conditional GET that hits the browser's
     * own cache still means the page was viewed, it just didn't need a
     * fresh body - excluding it would undercount every repeat visit on a
     * site with normal cache-revalidation headers (ETag/Last-Modified).
     * Every other 3xx is excluded on purpose: a redirect's own eventual 200
     * on the target page is what should count, not the hop that got it
     * there.
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
