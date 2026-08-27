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

namespace KonradMichalik\Typo3PageMetrics\Exception;

use RuntimeException;

/**
 * Ga4RequestFailedException.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Ga4RequestFailedException extends RuntimeException {}
