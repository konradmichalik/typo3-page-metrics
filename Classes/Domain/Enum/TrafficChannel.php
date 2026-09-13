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

namespace KonradMichalik\Typo3PageMetrics\Domain\Enum;

use function str_starts_with;
use function strlen;
use function substr;

/**
 * TrafficChannel.
 *
 * Where a page's visits came from, in the one vocabulary both providers fold
 * their own referrer types into.
 *
 * A provider keeps its own mapping, because which of its types is a referral
 * is knowledge about that provider: Matomo calls it "website", GA4 splits
 * eleven paid groups this treats as one campaign. What they share is only
 * the set of names they may arrive at, and the prefix those names are stored
 * under in the `dimension` column, which three classes previously spelled out
 * separately - a mismatch in any one of them would have dropped the split out
 * of the popover with nothing failing.
 *
 * The case order is the order the popover lists them in: the two a page
 * earns, then the one it is sent, then the one that was paid for, then the
 * remainder. Fixed rather than sorted by value, so a reader finds a channel
 * in the same place every time.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum TrafficChannel: string
{
    case Direct = 'direct';
    case Search = 'search';
    case Social = 'social';
    case Referral = 'referral';
    case Campaign = 'campaign';

    /**
     * Everything a provider cannot name, rather than a dropped row: a split
     * that silently loses traffic is worse than one that admits it cannot
     * say where a visit came from.
     */
    case Other = 'other';

    /**
     * The namespace these are stored under in tx_typo3pagemetrics_daily's
     * `dimension` column, which otherwise only ever carries `via_redirect`.
     */
    public const DIMENSION_PREFIX = 'channel:';

    public function dimension(): string
    {
        return self::DIMENSION_PREFIX.$this->value;
    }

    /**
     * Null for a dimension this vocabulary has no case for, including one a
     * newer provider version invented. The caller decides what to do with it,
     * and the popover shows it under its own name rather than hiding it.
     */
    public static function fromDimension(string $dimension): ?self
    {
        if (!str_starts_with($dimension, self::DIMENSION_PREFIX)) {
            return null;
        }

        return self::tryFrom(substr($dimension, strlen(self::DIMENSION_PREFIX)));
    }

    /**
     * English here rather than in the XLF for the same reason every metric
     * label a provider declares is: the split is provider vocabulary, and it
     * is named where the provider that produces it can be read beside it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Search => 'Search',
            self::Social => 'Social',
            self::Referral => 'Referral',
            self::Campaign => 'Campaign',
            self::Other => 'Other',
        };
    }
}
