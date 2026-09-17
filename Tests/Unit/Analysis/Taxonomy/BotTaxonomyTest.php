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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Analysis\Taxonomy;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\LogLine;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\{AgentMatcher, BotTaxonomy, Bucket};
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function sprintf;

/**
 * BotTaxonomyTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(BotTaxonomy::class)]
final class BotTaxonomyTest extends TestCase
{
    private BotTaxonomy $taxonomy;

    protected function setUp(): void
    {
        $this->taxonomy = new BotTaxonomy(new AgentMatcher());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function aiNeedleLists(): iterable
    {
        yield 'chatbots' => ['AI_CHATBOTS'];
        yield 'crawlers' => ['AI_CRAWLERS'];
    }

    /**
     * The two AI buckets list their agents by name in a tile popover, so a
     * needle without a description is a row a reader can hover for nothing.
     * The map is curated by hand and the needle lists grow whenever a vendor
     * ships a new crawler, which is exactly when the two drift apart.
     */
    #[Test]
    #[DataProvider('aiNeedleLists')]
    public function everyAgentOfATileBucketHasADescription(string $constant): void
    {
        $needles = (new ReflectionClass(BotTaxonomy::class))->getConstant($constant);
        self::assertIsArray($needles);
        self::assertNotSame([], $needles);

        foreach ($needles as $needle) {
            self::assertIsString($needle);
            self::assertNotNull(
                BotTaxonomy::describe($needle),
                sprintf('Agent "%s" is matched into a tile bucket but has no curated description.', $needle),
            );
        }
    }

    #[Test]
    public function describesNothingForAnAgentItHasNeverHeardOf(): void
    {
        // Null rather than a guessed sentence: an agent absent from the map
        // is one nobody has curated, not necessarily a suspicious one.
        self::assertNull(BotTaxonomy::describe('SomeVendorBot'));
    }

    #[Test]
    public function classifiesAnAiCrawlerFromARealUserAgent(): void
    {
        $result = $this->taxonomy->classify($this->line(
            '/robots.txt',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) '
                .'Chrome/131.0.0.0 Safari/537.36; ClaudeBot/1.0; +claudebot@anthropic.com)',
        ));

        self::assertNotNull($result);
        self::assertSame(Bucket::AiCrawler, $result->bucket);
        self::assertSame('ClaudeBot', $result->agent);
    }

    #[Test]
    public function classifiesAnAiChatbotUserAgent(): void
    {
        $result = $this->taxonomy->classify($this->line('/', 'Mozilla/5.0 ChatGPT-User/1.0'));

        self::assertNotNull($result);
        self::assertSame(Bucket::AiChatbot, $result->bucket);
        self::assertSame('ChatGPT-User', $result->agent);
    }

    #[Test]
    public function classifiesASocialUnfurlingUserAgent(): void
    {
        $result = $this->taxonomy->classify($this->line('/article/1', 'facebookexternalhit/1.1'));

        self::assertNotNull($result);
        self::assertSame(Bucket::Social, $result->bucket);
    }

    #[Test]
    public function classifiesASearchIndexerUserAgent(): void
    {
        $result = $this->taxonomy->classify($this->line('/', 'Mozilla/5.0 (compatible; Googlebot/2.1)'));

        self::assertNotNull($result);
        self::assertSame(Bucket::Search, $result->bucket);
    }

    #[Test]
    public function classifiesAToolUserAgent(): void
    {
        $result = $this->taxonomy->classify($this->line('/', 'curl/8.4.0'));

        self::assertNotNull($result);
        self::assertSame(Bucket::Tool, $result->bucket);
    }

    #[Test]
    public function returnsNullForARegularBrowser(): void
    {
        $result = $this->taxonomy->classify($this->line('/', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'));

        self::assertNull($result);
    }

    #[Test]
    public function returnsNullForStaticAssetsEvenWithAKnownBotUserAgent(): void
    {
        $result = $this->taxonomy->classify($this->line('/assets/style.css?v=3', 'GPTBot/1.0'));

        self::assertNull($result);
    }

    #[Test]
    public function aiChatbotListTakesPrecedenceOverAiCrawlerList(): void
    {
        $result = $this->taxonomy->classify($this->line('/', 'ChatGPT-User/1.0 GPTBot/1.0'));

        self::assertNotNull($result);
        self::assertSame(Bucket::AiChatbot, $result->bucket);
    }

    #[Test]
    public function describeReturnsACuratedOneLinerForAKnownAgent(): void
    {
        $gptBot = BotTaxonomy::describe('GPTBot');
        $ahrefsBot = BotTaxonomy::describe('AhrefsBot');

        self::assertNotNull($gptBot);
        self::assertNotNull($ahrefsBot);
        self::assertStringContainsString('OpenAI', $gptBot);
        self::assertStringContainsString('Ahrefs', $ahrefsBot);
    }

    #[Test]
    public function describeReturnsNullForAnAgentNotInTheCuratedList(): void
    {
        // Best-effort, not exhaustive: an uncurated agent (or a typo in
        // the lookup key) must fail closed to null, never a guessed or
        // partially-matched description.
        self::assertNull(BotTaxonomy::describe('SomeBrandNewBot'));
        self::assertNull(BotTaxonomy::describe('gptbot'));
    }

    private function line(string $target, string $userAgent): LogLine
    {
        return new LogLine(
            ip: '216.73.217.0',
            timestamp: new DateTimeImmutable('2026-08-23 23:59:57'),
            method: 'GET',
            target: $target,
            protocol: 'HTTP/1.1',
            status: 200,
            bytesSent: 5731,
            referer: null,
            userAgent: $userAgent,
            host: 'www.move-elevator.de',
        );
    }
}
