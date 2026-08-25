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

namespace KonradMichalik\Typo3PageMetrics\Domain\Enum;

/**
 * UnresolvedReason.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum UnresolvedReason: string
{
    case ForeignHost = 'foreign-host';
    case File = 'file';
    case NonPage = 'non-page';
    case Unknown = 'unknown';
}
