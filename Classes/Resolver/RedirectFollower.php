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

namespace KonradMichalik\Typo3PageMetrics\Resolver;

use Doctrine\DBAL\{Exception as DBALException, ParameterType};
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;

use function is_string;

/**
 * RedirectFollower.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RedirectFollower
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private SiteFinder $siteFinder,
    ) {}

    public function findTarget(string $host, string $path): ?string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        try {
            $row = $queryBuilder
                ->select('target')
                ->from('sys_redirect')
                ->where(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('source_host', $queryBuilder->createNamedParameter('*')),
                        $queryBuilder->expr()->eq('source_host', $queryBuilder->createNamedParameter($host)),
                    ),
                    $queryBuilder->expr()->eq('source_path', $queryBuilder->createNamedParameter($path)),
                    $queryBuilder->expr()->eq('disabled', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                )
                ->executeQuery()
                ->fetchAssociative();
        } catch (DBALException) {
            // typo3/cms-redirects is optional (require-dev only); installations
            // without it have no sys_redirect table at all. From the Resolver's
            // perspective that is indistinguishable from "no redirect found".
            return null;
        }

        if (false === $row || !is_string($row['target']) || '' === $row['target']) {
            return null;
        }

        return $this->resolveTargetToUrl($row['target']);
    }

    private function resolveTargetToUrl(string $target): ?string
    {
        if (1 === preg_match('/^t3:\/\/page\?uid=(\d+)/', $target, $matches)) {
            $pageUid = (int) $matches[1];

            try {
                $site = $this->siteFinder->getSiteByPageId($pageUid);

                return (string) $site->getRouter()->generateUri($pageUid);
            } catch (Throwable) {
                return null;
            }
        }

        if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://') || str_starts_with($target, '/')) {
            return $target;
        }

        return null;
    }
}
