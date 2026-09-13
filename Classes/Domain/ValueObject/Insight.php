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

namespace KonradMichalik\Typo3PageMetrics\Domain\ValueObject;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrendSentiment;

use function in_array;

/**
 * Insight.
 *
 * One card of the "what changed" widget: a whole sentence about one
 * movement, plus the two numbers the widget needs to order it against the
 * others.
 *
 * The sentence is not assembled here, and deliberately not assembled
 * anywhere in PHP. This object carries the key of a full sentence in the
 * language file and the values that go into it, so the entire word order
 * stays inside the translation. That is also why the direction is part of
 * the key (SENTENCE_SITE_UP against SENTENCE_SITE_DOWN) rather than one
 * more substituted value: a language whose verb changes shape, position or
 * agreement with the direction cannot be served by dropping "up" or "down"
 * into a fixed frame, and a widget whose one job is to state a direction
 * cannot afford to get that wrong. The cost is that every sentence exists
 * twice in the language file.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class Insight
{
    public const SENTENCE_SITE_UP = 'siteUp';
    public const SENTENCE_SITE_DOWN = 'siteDown';
    public const SENTENCE_PAGE_RISE = 'pageRise';
    public const SENTENCE_PAGE_FALL = 'pageFall';

    /**
     * @param string           $siteIdentifier    the site this card speaks for, drawn beside the sentence only when the widget covers more than one
     * @param string           $sentenceKey       one of the SENTENCE_* constants, the suffix of the widget.insight.* label the template renders
     * @param list<string|int> $sentenceArguments in the order the sentence's own %1$s..%4$s expect them
     * @param TrendSentiment   $sentiment         whether this movement is an improvement, which only the metric's polarity can say; carries the card's accent colour and nothing else, since the direction is already in the words
     * @param float            $score             how many times over its own MetricSignificance the movement went, which is what lets a count and a rate be ranked against each other at all (see InsightBuilder::score())
     */
    public function __construct(
        public string $siteIdentifier,
        public string $sentenceKey,
        public array $sentenceArguments,
        public TrendSentiment $sentiment,
        public float $score,
    ) {}

    /**
     * Whether this card states the site's own movement rather than one
     * page's. The widget ranks every site-level card above every page-level
     * one, so this is the first key it sorts by.
     */
    public function isSiteLevel(): bool
    {
        return in_array($this->sentenceKey, [self::SENTENCE_SITE_UP, self::SENTENCE_SITE_DOWN], true);
    }
}
