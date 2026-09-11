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

use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\AgentMatcher;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * AgentMatcherTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(AgentMatcher::class)]
final class AgentMatcherTest extends TestCase
{
    private AgentMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new AgentMatcher();
    }

    #[Test]
    public function matchesCaseInsensitiveSubstring(): void
    {
        $result = $this->matcher->match(
            'Mozilla/5.0 ... claudebot/1.0; +claudebot@anthropic.com',
            ['ClaudeBot', 'GPTBot'],
        );

        self::assertSame('ClaudeBot', $result);
    }

    #[Test]
    public function returnsNullWhenNothingMatches(): void
    {
        $result = $this->matcher->match('Mozilla/5.0 (Windows NT 10.0; Win64; x64)', ['ClaudeBot', 'GPTBot']);

        self::assertNull($result);
    }

    #[Test]
    public function firstMatchingNeedleInListOrderWins(): void
    {
        $result = $this->matcher->match('ChatGPT-User/1.0 GPTBot/1.0', ['GPTBot', 'ChatGPT-User']);

        self::assertSame('GPTBot', $result);
    }
}
