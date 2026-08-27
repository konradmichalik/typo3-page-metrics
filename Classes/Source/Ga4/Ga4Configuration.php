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

namespace KonradMichalik\Typo3PageMetrics\Source\Ga4;

/**
 * Ga4Configuration.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class Ga4Configuration
{
    public function __construct(
        public string $propertyId,
        public string $clientEmail,
        public string $privateKey,
    ) {}
}
