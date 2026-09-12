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

namespace KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy;

use KonradMichalik\Typo3PageMetrics\Analysis\Parser\LogLine;

/**
 * BotTaxonomy.
 *
 * Classifies a log line into one of five bot buckets by matching its
 * User-Agent against curated per-bucket lists, in a fixed precedence
 * order. Static asset requests are never classified, matching the
 * reference `ai-log-probe.php` measurements this extension's numbers
 * are validated against.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class BotTaxonomy
{
    /**
     * Accepted by Matomo's AI-chatbot tracking allowlist.
     *
     * @var list<string>
     */
    private const AI_CHATBOTS = [
        'ChatGPT-User', 'Claude-User', 'Perplexity-User', 'MistralAI-User',
        'Gemini-Deep-Research', 'GoogleAgent', 'Google-NotebookLM', 'NovaAct',
    ];

    /**
     * AI training/search crawlers — recognized but discarded by Matomo.
     *
     * @var list<string>
     */
    private const AI_CRAWLERS = [
        'GPTBot', 'OAI-SearchBot', 'ChatGPT-Agent',
        'ClaudeBot', 'Claude-SearchBot', 'anthropic-ai', 'Claude-Web',
        'PerplexityBot', 'CCBot', 'Bytespider',
        'meta-externalagent', 'Meta-ExternalFetcher',
        'Google-Extended', 'Applebot-Extended',
        'Amazonbot', 'cohere-ai', 'Diffbot', 'ImagesiftBot',
        'YouBot', 'DuckAssistBot', 'AI2Bot', 'Timpibot', 'omgili',
        'FirecrawlAgent', 'PanguBot', 'Webzio-Extended', 'Kangaroo Bot',
    ];

    /**
     * Link-preview / social unfurling — its own bucket, not an indexer.
     *
     * @var list<string>
     */
    private const SOCIAL_BOTS = [
        'facebookexternalhit', 'facebookcatalog', 'LinkedInBot', 'Twitterbot',
        'Slackbot', 'WhatsApp', 'TelegramBot', 'Discordbot', 'Pinterest',
        'redditbot', 'Embedly', 'SkypeUriPreview', 'vkShare', 'Iframely',
    ];

    /**
     * Real search engine indexers.
     *
     * @var list<string>
     */
    private const SEARCH_BOTS = [
        'Googlebot', 'Storebot-Google', 'Google-InspectionTool', 'AdsBot-Google',
        'bingbot', 'BingPreview', 'Applebot', 'DuckDuckBot',
        'YandexBot', 'Baiduspider', 'Sogou', 'SeznamBot', 'Qwantify', 'Slurp',
    ];

    /**
     * SEO tools, scrapers, monitoring, generic HTTP clients.
     *
     * @var list<string>
     */
    private const TOOL_BOTS = [
        'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'BLEXBot',
        'DataForSeoBot', 'PetalBot', 'Screaming Frog', 'SiteAuditBot',
        'UptimeRobot', 'Pingdom', 'Site24x7', 'StatusCake', 'Uptime-Kuma',
        'curl/', 'wget', 'python-requests', 'Go-http-client', 'axios/',
        'Scrapy', 'HeadlessChrome', 'Java/', 'okhttp',
    ];

    private const STATIC_ASSET_PATTERN = '#\.(css|js|mjs|map|png|jpe?g|gif|svg|webp|avif|ico|bmp|'
        .'woff2?|ttf|otf|eot|mp4|webm|mp3|zip|gz)(\?|$)#i';

    /**
     * One-line explanation per known agent, keyed by the exact needle
     * string {@see AgentMatcher::match()} returns (not the raw UA
     * header), so a lookup by an `AgentDailyAggregate::$agent` value
     * always hits directly. Best-effort and not exhaustive: an agent
     * absent here is simply one this list hasn't been curated for yet,
     * not necessarily an unknown/suspicious one - {@see describe()}
     * returns null for it rather than a guessed description.
     *
     * @var array<string, string>
     */
    private const AGENT_DESCRIPTIONS = [
        // AI chatbots: fetched live in response to a specific user
        // request, not a bulk crawl.
        'ChatGPT-User' => "Fetches a page live when a ChatGPT user asks about or shares that page's URL.",
        'Claude-User' => 'Fetches a page live when a user references its URL in a Claude conversation.',
        'Perplexity-User' => 'Fetches a page live when a user asks Perplexity about its URL.',
        'MistralAI-User' => "Fetches a page live on behalf of a Mistral AI (Le Chat) user's request.",
        'Gemini-Deep-Research' => "Google Gemini's Deep Research agent, fetching pages while compiling a report.",
        'GoogleAgent' => "Google's general AI agent user agent for on-demand page fetches.",
        'Google-NotebookLM' => 'Fetches a page when a NotebookLM user adds it as a source.',
        'NovaAct' => "Amazon's Nova Act browser-automation agent, acting on a user's behalf.",
        // AI crawlers: bulk crawling for training data or an AI search
        // index, not triggered by one specific user request.
        'GPTBot' => "OpenAI's crawler, collecting web content to train future models.",
        'OAI-SearchBot' => "OpenAI's crawler for ChatGPT's search feature.",
        'ChatGPT-Agent' => "OpenAI's autonomous browsing agent, completing multi-step tasks.",
        'ClaudeBot' => "Anthropic's crawler, collecting web content to train Claude.",
        'Claude-SearchBot' => "Anthropic's crawler, indexing pages for Claude's search feature.",
        'anthropic-ai' => 'An earlier Anthropic crawler user agent.',
        'Claude-Web' => "Anthropic's crawler for Claude's web-browsing feature.",
        'PerplexityBot' => "Perplexity AI's crawler, building its search index.",
        'CCBot' => "Common Crawl's crawler; its archive is widely reused as AI training data.",
        'Bytespider' => "ByteDance's crawler, used for its AI models and search.",
        'meta-externalagent' => "Meta's crawler, collecting AI training data and building link previews.",
        'Meta-ExternalFetcher' => "Meta's crawler for on-demand link/content fetches.",
        'Google-Extended' => "Opts a site's content in or out of Google's Gemini/AI training specifically, separate from Googlebot's own search indexing.",
        'Applebot-Extended' => "Opts a site's content in or out of Apple's AI (Apple Intelligence) training specifically.",
        'Amazonbot' => "Amazon's crawler, used for Alexa and product-search features.",
        'cohere-ai' => "Cohere's crawler, collecting training data for its language models.",
        'Diffbot' => 'A commercial web-scraping/structured-data extraction service.',
        'ImagesiftBot' => 'A crawler collecting image data, reportedly for AI training datasets.',
        'YouBot' => "You.com's crawler, building its AI search index.",
        'DuckAssistBot' => "DuckDuckGo's crawler, powering its AI-assisted search summaries.",
        'AI2Bot' => 'The Allen Institute for AI\'s (AI2) crawler, collecting open training data.',
        'Timpibot' => "Timpi's crawler, a decentralized search engine project.",
        'omgili' => "Webz.io's crawler, collecting data for research and AI training.",
        'FirecrawlAgent' => "Firecrawl's crawler, a service that converts sites into LLM-ready data.",
        'PanguBot' => "Huawei's crawler, collecting training data for its Pangu AI models.",
        'Webzio-Extended' => "Webz.io's crawler, collecting content for its data/AI licensing business.",
        // Social / link-preview unfurling.
        'facebookexternalhit' => "Meta's crawler, generating link previews for Facebook/Instagram shares.",
        'facebookcatalog' => "Meta's crawler, validating product catalog/shopping feed links.",
        'LinkedInBot' => "LinkedIn's crawler, generating link previews for shared posts.",
        'Twitterbot' => "X's (formerly Twitter's) crawler, generating link preview cards.",
        'Slackbot' => "Slack's crawler, generating link previews inside messages.",
        'WhatsApp' => "WhatsApp's crawler, generating link previews for shared messages.",
        'TelegramBot' => "Telegram's crawler, generating link previews for shared messages.",
        'Discordbot' => "Discord's crawler, generating link preview embeds for shared messages.",
        'Pinterest' => "Pinterest's crawler, fetching page images/metadata for pins.",
        'redditbot' => "Reddit's crawler, generating link previews for submitted posts.",
        'Embedly' => 'A third-party embed/link-preview service used by many apps.',
        'SkypeUriPreview' => "Skype's crawler, generating link previews in chats.",
        'vkShare' => "VKontakte's crawler, generating link previews for shared posts.",
        'Iframely' => 'A third-party embed/link-preview service used by many apps.',
        // Search indexers.
        'Googlebot' => "Google's main crawler, building its search index.",
        'Storebot-Google' => "Google's crawler for Google Shopping/product listings.",
        'Google-InspectionTool' => "Google's on-demand crawler behind Search Console's URL inspection/testing tools.",
        'AdsBot-Google' => "Google's crawler, checking landing-page quality for Google Ads.",
        'bingbot' => "Microsoft Bing's crawler, building its search index.",
        'BingPreview' => "Microsoft's crawler, generating link previews for Bing/Skype/Teams.",
        'Applebot' => "Apple's crawler, powering Siri and Spotlight Suggestions search results.",
        'DuckDuckBot' => "DuckDuckGo's crawler, building its search index.",
        'YandexBot' => "Yandex's crawler, building its search index.",
        'Baiduspider' => "Baidu's crawler, building its search index.",
        'Sogou' => "Sogou's crawler, building its search index.",
        'SeznamBot' => "Seznam.cz's crawler, building its search index.",
        'Qwantify' => "Qwant's crawler, building its search index.",
        'Slurp' => "Yahoo's crawler, building its search index.",
        // SEO tools, monitoring, generic HTTP clients.
        'AhrefsBot' => "Ahrefs' crawler, collecting backlink and SEO data.",
        'SemrushBot' => "Semrush's crawler, collecting backlink and SEO data.",
        'MJ12bot' => "Majestic's crawler, collecting backlink data.",
        'DotBot' => "Moz's crawler, collecting backlink and SEO data.",
        'BLEXBot' => "WebMeUp's crawler, collecting backlink data.",
        'DataForSeoBot' => "DataForSEO's crawler, collecting SEO/SERP data.",
        'PetalBot' => "Huawei's crawler, for its Petal Search engine.",
        'Screaming Frog' => 'The Screaming Frog SEO Spider desktop tool, run manually by a site auditor.',
        'SiteAuditBot' => 'A generic/vendor site-auditing crawler.',
        'UptimeRobot' => 'The UptimeRobot uptime-monitoring service, checking if the site is reachable.',
        'Pingdom' => 'The Pingdom uptime-monitoring service, checking if the site is reachable.',
        'Site24x7' => 'The Site24x7 uptime-monitoring service, checking if the site is reachable.',
        'StatusCake' => 'The StatusCake uptime-monitoring service, checking if the site is reachable.',
        'Uptime-Kuma' => 'A self-hosted Uptime Kuma instance, checking if the site is reachable.',
        'curl/' => 'The curl command-line HTTP client, used from a script or manually.',
        'wget' => 'The wget command-line HTTP client, used from a script or manually.',
        'python-requests' => 'A Python script using the requests HTTP library.',
        'Go-http-client' => 'A Go program using its standard-library HTTP client.',
        'axios/' => 'A Node.js/JavaScript script using the axios HTTP library.',
        'Scrapy' => 'A Python script built with the Scrapy web-scraping framework.',
        'HeadlessChrome' => 'A headless (scripted) Chrome browser, often used for automated testing or scraping.',
        'Java/' => 'A Java program using its built-in HTTP client.',
        'okhttp' => 'An Android/Java program using the OkHttp library.',
    ];

    public function __construct(
        private readonly AgentMatcher $agentMatcher,
    ) {}

    /**
     * Best-effort, not exhaustive: returns null for any agent this list
     * has not been curated for, rather than fabricating a description.
     */
    public static function describe(string $agent): ?string
    {
        return self::AGENT_DESCRIPTIONS[$agent] ?? null;
    }

    public function classify(LogLine $line): ?Classification
    {
        if (1 === preg_match(self::STATIC_ASSET_PATTERN, $line->target)) {
            return null;
        }

        foreach ($this->bucketsInPrecedenceOrder() as [$bucket, $needles]) {
            $agent = $this->agentMatcher->match($line->userAgent, $needles);
            if (null !== $agent) {
                return new Classification($bucket, $agent);
            }
        }

        return null;
    }

    /**
     * @return list<array{0: Bucket, 1: list<string>}>
     */
    private function bucketsInPrecedenceOrder(): array
    {
        return [
            [Bucket::AiChatbot, self::AI_CHATBOTS],
            [Bucket::AiCrawler, self::AI_CRAWLERS],
            [Bucket::Social, self::SOCIAL_BOTS],
            [Bucket::Search, self::SEARCH_BOTS],
            [Bucket::Tool, self::TOOL_BOTS],
        ];
    }
}
