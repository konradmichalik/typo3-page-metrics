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

use function preg_replace;
use function sprintf;

/**
 * CsvExport.
 *
 * One view of the backend module as a file: what it is called, what its
 * columns are, and the rows themselves.
 *
 * $rows is an iterable and is meant to be a generator. A site-wide export is
 * as long as the site, and the whole point of not typing it as an array is
 * that CsvStreamWriter can push each row into a disk-backed stream and let
 * it go again. It follows that a CsvExport is readable exactly once.
 *
 * Nothing in here is a formatted string. Values arrive in the unit the fact
 * table stores them in, so a duration is a count of seconds and money is
 * minor units; what the module renders is a reading aid and would be a lie
 * in a column somebody sums.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CsvExport
{
    /**
     * @param list<string>                    $header
     * @param iterable<list<string|int|null>> $rows
     */
    public function __construct(
        public string $filename,
        public array $header,
        public iterable $rows,
    ) {}

    /**
     * The period goes in the name rather than into a column on every row.
     * Two files downloaded a month apart are otherwise indistinguishable in
     * a downloads folder, which is where they are opened from.
     *
     * $subject is a site identifier on the site-wide views, and that is
     * editor input reaching a Content-Disposition header: anything outside
     * the allowed set becomes a hyphen here, so the name cannot carry a
     * quote, a newline or a path separator into the response.
     *
     * @param list<string>                    $header
     * @param iterable<list<string|int|null>> $rows
     */
    public static function named(string $view, string $subject, DateRange $range, array $header, iterable $rows): self
    {
        return new self(
            sprintf(
                'page-metrics-%s-%s-%s_%s.csv',
                self::slug($view),
                self::slug($subject),
                $range->from->format('Y-m-d'),
                $range->until->format('Y-m-d'),
            ),
            $header,
            $rows,
        );
    }

    private static function slug(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $value);
    }
}
