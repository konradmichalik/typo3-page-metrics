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

/**
 * RecordReference.
 *
 * The record a URL was about, as TYPO3 itself identifies a record: a table
 * name and a uid.
 *
 * The uid is always the default-language one. A persisted route mapper
 * returns the translation parent for a translated record, exactly as
 * SlugLookup returns the default-language page uid for a translated page, so
 * "the record" means the same thing in every language and the language stays
 * a dimension of the stored fact rather than part of its identity.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RecordReference
{
    public function __construct(
        public string $table,
        public int $uid,
    ) {}
}
