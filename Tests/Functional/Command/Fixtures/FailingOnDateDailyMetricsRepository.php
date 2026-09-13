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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Command\Fixtures;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Persistence\DailyMetricsRepository;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;

use function sprintf;

/**
 * FailingOnDateDailyMetricsRepository.
 *
 * Writes normally for every day except one, where it throws instead. Stands
 * in for the realistic mid-run write failure (a deadlock, a lock wait
 * timeout, a connection dropped by the server) that the import command's
 * per-day commits and its write-the-cursor-last ordering exist for.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class FailingOnDateDailyMetricsRepository extends DailyMetricsRepository
{
    public function __construct(
        ConnectionPool $connectionPool,
        private readonly DateTimeImmutable $failingDate,
    ) {
        parent::__construct($connectionPool);
    }

    public function upsert(int $pageUid, int $languageUid, string $provider, DateTimeImmutable $date, string $metric, int $value, ?string $dimension = null): void
    {
        if ($date->format('Y-m-d') === $this->failingDate->format('Y-m-d')) {
            throw new RuntimeException(sprintf('Simulated write failure for %s.', $this->failingDate->format('Y-m-d')), 1394827105);
        }

        parent::upsert($pageUid, $languageUid, $provider, $date, $metric, $value, $dimension);
    }
}
