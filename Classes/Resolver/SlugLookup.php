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

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\{DeletedRestriction, HiddenRestriction};

/**
 * SlugLookup.
 *
 * Language-aware slug lookup against the pages table. Returns the
 * default-language page uid even for a translated hit (via l10n_parent),
 * so tx_typo3pagemetrics_daily's page_uid column identifies "the page" across
 * languages, with sys_language_uid carrying the language dimension.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SlugLookup
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function find(string $slug, int $languageId): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new HiddenRestriction());

        $row = $queryBuilder
            ->select('uid', 'l10n_parent')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($slug)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        $l10nParent = (int) $row['l10n_parent'];

        return $languageId > 0 && $l10nParent > 0 ? $l10nParent : (int) $row['uid'];
    }
}
