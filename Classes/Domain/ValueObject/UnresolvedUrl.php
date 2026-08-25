<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Domain\ValueObject;

use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;

/**
 * UnresolvedUrl.
 *
 * A first-class result, not an error: "can't resolve" is an expected
 * outcome of Resolver::resolve(), never an exception.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class UnresolvedUrl
{
    public function __construct(
        public string $url,
        public UnresolvedReason $reason,
    ) {}
}
