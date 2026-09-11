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

namespace KonradMichalik\Typo3PageMetrics\Analysis\Parser;

use DateTimeImmutable;

use function is_string;

/**
 * LogLine.
 *
 * One parsed Combined Log Format access log entry. This is the raw,
 * unnormalized shape: `target` is exactly what appeared in the request
 * line (path plus query string, if any). Normalization and TYPO3 page
 * resolution happen later, outside this package.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class LogLine
{
    public function __construct(
        public string $ip,
        public DateTimeImmutable $timestamp,
        public ?string $method,
        public string $target,
        public ?string $protocol,
        public int $status,
        public ?int $bytesSent,
        public ?string $referer,
        public string $userAgent,
        public ?string $host,
    ) {}

    public function path(): string
    {
        $path = parse_url($this->target, \PHP_URL_PATH);

        return is_string($path) ? $path : $this->target;
    }
}
