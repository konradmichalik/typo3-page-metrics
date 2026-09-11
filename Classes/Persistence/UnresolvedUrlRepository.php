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
use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};

use function array_slice;

/**
 * UnresolvedUrlRepository.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class UnresolvedUrlRepository
{
    private const TABLE = 'tx_typo3pagemetrics_unresolved';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function record(string $provider, DateTimeImmutable $date, string $url, string $metric, UnresolvedReason $reason, int $value, int $capPerDay): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $dateString = $date->format('Y-m-d');
        $identity = [
            'date' => $dateString,
            'provider' => $provider,
            // Truncated to match the varchar(40) column, same length as a full SHA-1
            // digest would occupy, without using the disallowed sha1() function.
            'url_hash' => substr(hash('sha256', $url), 0, 40),
            'metric' => $metric,
        ];

        $existingUid = $connection->select(['uid'], self::TABLE, $identity)->fetchOne();

        if (false !== $existingUid) {
            $connection->update(self::TABLE, ['value' => $value, 'reason' => $reason->value, 'url' => $url], ['uid' => $existingUid]);

            return;
        }

        $connection->insert(self::TABLE, array_merge($identity, ['url' => $url, 'reason' => $reason->value, 'value' => $value]));

        $this->trimToCap($provider, $dateString, $capPerDay);
    }

    /**
     * Bot metrics are excluded on purpose, here and in listForSite(): both
     * feed human-facing "not found pages" surfaces, and an access log's bot
     * traffic records an unresolved row for every dead link, /wp-login.php
     * and /.env probe it carries, which would bury the handful of URLs a
     * human actually needs to see. The bot side gets its own surface.
     */
    public function countForSite(string $host, DateTimeImmutable $since): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $likePattern = '%://'.$queryBuilder->escapeLikeWildcards($host).'/%';

        return (int) $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->like('url', $queryBuilder->createNamedParameter($likePattern)),
                $queryBuilder->expr()->gte('date', $queryBuilder->createNamedParameter($since->format('Y-m-d'))),
                $queryBuilder->expr()->notLike('metric', $queryBuilder->createNamedParameter('bot.%')),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * The highest-value unresolved URLs for a site, one row per url+reason,
     * summed across every day in range - the "not found pages" widget's
     * data source. Bot metrics are excluded for the reason countForSite()
     * spells out.
     *
     * @return list<array{url: string, reason: string, value: int}>
     */
    public function listForSite(string $host, DateTimeImmutable $since, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $likePattern = '%://'.$queryBuilder->escapeLikeWildcards($host).'/%';

        $rows = $queryBuilder
            ->select('url', 'reason')
            ->addSelectLiteral('SUM('.$queryBuilder->quoteIdentifier('value').') AS total')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->like('url', $queryBuilder->createNamedParameter($likePattern)),
                $queryBuilder->expr()->gte('date', $queryBuilder->createNamedParameter($since->format('Y-m-d'))),
                $queryBuilder->expr()->notLike('metric', $queryBuilder->createNamedParameter('bot.%')),
            )
            ->groupBy('url', 'reason')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => ['url' => (string) $row['url'], 'reason' => (string) $row['reason'], 'value' => (int) $row['total']],
            $rows,
        );
    }

    /**
     * Keeps the $capPerDay highest-value rows for the day, deletes the rest -
     * the lowest-value entries are the least actionable ones to lose.
     */
    private function trimToCap(string $provider, string $date, int $capPerDay): void
    {
        $selectQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $uids = $selectQueryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $selectQueryBuilder->expr()->eq('provider', $selectQueryBuilder->createNamedParameter($provider)),
                $selectQueryBuilder->expr()->eq('date', $selectQueryBuilder->createNamedParameter($date)),
            )
            ->orderBy('value', 'DESC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        $excessUids = array_slice($uids, $capPerDay);

        if ([] === $excessUids) {
            return;
        }

        $deleteQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $deleteQueryBuilder
            ->delete(self::TABLE)
            ->where($deleteQueryBuilder->expr()->in(
                'uid',
                $deleteQueryBuilder->createNamedParameter($excessUids, Connection::PARAM_INT_ARRAY),
            ))
            ->executeStatement();
    }
}
