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

namespace KonradMichalik\Typo3PageMetrics\Import;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\RecordReference;

use function implode;

/**
 * RecordAggregate.
 *
 * One import run's facts for the records a route enhancer placed below a
 * page, summed in memory before anything is written, for the same reason the
 * page aggregate is: two distinct source URLs can land on the same record and
 * day, and upsert() is a set operation, so the second would replace the first
 * rather than add to it.
 *
 * Keyed by the record rather than by the page it was reached through. A
 * record listed below two detail pages is one record, and "how is this
 * article doing" has to answer the same number either way.
 *
 * Its own object rather than two more methods on ImportCommand: that class
 * already carries the page aggregate, the per-day commit and the cursor, and
 * a second aggregate with its own key shape was what pushed it over the
 * cognitive-complexity ceiling.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RecordAggregate
{
    /**
     * @var array<string, array{record: RecordReference, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}>
     */
    private array $rows = [];

    public function add(RecordReference $record, int $languageUid, DateTimeImmutable $date, string $metric, string $dimension, int $value): void
    {
        $key = implode('|', [$date->format('Y-m-d'), $record->table, $record->uid, $languageUid, $metric, $dimension]);

        if (isset($this->rows[$key])) {
            $this->rows[$key]['value'] += $value;

            return;
        }

        $this->rows[$key] = [
            'record' => $record,
            'languageUid' => $languageUid,
            'date' => $date,
            'metric' => $metric,
            'dimension' => $dimension,
            'value' => $value,
        ];
    }

    /**
     * Grouped by day, so each day's rows travel in that day's own
     * transaction. A record only ever belongs to a day the page aggregate
     * also has, because both are read off the same UrlMetric.
     *
     * @return array<string, list<array{record: RecordReference, languageUid: int, date: DateTimeImmutable, metric: string, dimension: string, value: int}>>
     */
    public function byDay(): array
    {
        $byDay = [];

        foreach ($this->rows as $row) {
            $byDay[$row['date']->format('Y-m-d')][] = $row;
        }

        return $byDay;
    }
}
