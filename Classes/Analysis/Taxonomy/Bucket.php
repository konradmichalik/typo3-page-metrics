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

namespace KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy;

/**
 * Bucket.
 *
 * The five bot traffic buckets this extension distinguishes. Values are
 * the exact codes stored in the database (spec §6.1/§7.1) — do not
 * rename without a migration.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum Bucket: string
{
    case AiChatbot = 'ai_chatbot';
    case AiCrawler = 'ai_crawler';
    case Search = 'search';
    case Social = 'social';
    case Tool = 'tool';
}
