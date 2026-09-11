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

namespace KonradMichalik\Typo3PageMetrics\Persistence;

use DateTimeImmutable;
use TYPO3\CMS\Core\Database\ConnectionPool;

use function sha1;

/**
 * BotAgentDailyRepository.
 *
 * Same select-then-insert-or-update upsert style as DailyMetricsRepository,
 * for the same reason (see that class's docblock) - and the same replace,
 * never add, semantics: a rotated log re-imported after a hash change must
 * land on the same total, not double-count on top of it.
 *
 * agent_hash (sha1 of the agent string) carries the identity instead of the
 * agent column itself, keeping the unique index narrow regardless of how
 * long a given agent string is - agent itself is stored purely as display
 * payload, updated alongside hits/hits_suspicious on every upsert() so a
 * hash collision (astronomically unlikely for this cardinality, but not
 * impossible) still self-corrects to the most recently written spelling.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class BotAgentDailyRepository
{
    private const TABLE = 'tx_typo3pagemetrics_bot_agent_daily';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function upsert(string $siteIdentifier, int $pageUid, int $languageUid, string $bucket, string $agent, DateTimeImmutable $date, int $hits, int $hitsSuspicious): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identity = [
            'date' => $date->format('Y-m-d'),
            'site_identifier' => $siteIdentifier,
            'page_uid' => $pageUid,
            'sys_language_uid' => $languageUid,
            'bucket' => $bucket,
            'agent_hash' => sha1($agent),
        ];

        $existingUid = $connection->select(['uid'], self::TABLE, $identity)->fetchOne();

        if (false !== $existingUid) {
            $connection->update(self::TABLE, ['agent' => $agent, 'hits' => $hits, 'hits_suspicious' => $hitsSuspicious], ['uid' => $existingUid]);

            return;
        }

        $connection->insert(self::TABLE, array_merge($identity, ['agent' => $agent, 'hits' => $hits, 'hits_suspicious' => $hitsSuspicious]));
    }
}
