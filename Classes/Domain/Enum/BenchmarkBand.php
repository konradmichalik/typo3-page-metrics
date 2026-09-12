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

use function count;

/**
 * BenchmarkBand.
 *
 * Where a page sits among the other pages of its site, as a band rather than
 * a number.
 *
 * A precise percentile is false precision on a site with eleven pages: the
 * difference between the 63rd and the 72nd is one page changing its mind, and
 * a figure that moves that easily invites a reading it cannot support. Four
 * bands say the only thing the data reliably supports, which is roughly where
 * in the field this page is.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum BenchmarkBand: string
{
    case TopFifth = 'topFifth';
    case AboveMedian = 'aboveMedian';
    case AtMedian = 'atMedian';
    case BelowMedian = 'belowMedian';
    case BottomFifth = 'bottomFifth';

    /**
     * @param list<int> $values every page of the site that has data
     */
    public static function of(int $value, int $median, array $values): self
    {
        // Against the median itself first, as an integer comparison. The
        // share below is a float, and asking whether it lands exactly on a
        // half is the kind of question floats answer badly - while "is this
        // page the typical one" is exactly the question being asked.
        if ($value === $median) {
            return self::AtMedian;
        }

        // Midrank: pages on the same number as this one count half. Counting
        // them fully would put every page of a nearly flat site in the top
        // fifth, and counting none of them would put every page in the
        // bottom one - both of which say more about the tie-breaking than
        // about the page.
        $below = 0;
        $equal = 0;
        foreach ($values as $other) {
            if ($other < $value) {
                ++$below;
            } elseif ($other === $value) {
                ++$equal;
            }
        }

        $share = ($below + $equal / 2) / count($values);

        return match (true) {
            $share > 0.8 => self::TopFifth,
            $share < 0.2 => self::BottomFifth,
            $value > $median => self::AboveMedian,
            default => self::BelowMedian,
        };
    }
}
