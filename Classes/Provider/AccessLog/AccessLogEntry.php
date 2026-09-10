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

namespace KonradMichalik\Typo3PageMetrics\Provider\AccessLog;

use DateTimeImmutable;

/**
 * AccessLogEntry.
 *
 * One successfully parsed combined-log-format line, before any bot,
 * status-code or static-asset filtering - see AccessLogLineParser.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class AccessLogEntry
{
    public function __construct(
        public string $ipAddress,
        public DateTimeImmutable $date,
        public string $method,
        public string $path,
        public int $statusCode,
        public string $userAgent,
    ) {}
}
