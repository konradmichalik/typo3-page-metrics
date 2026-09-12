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

namespace KonradMichalik\Typo3PageMetrics\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DataFreshness;
use KonradMichalik\Typo3PageMetrics\Persistence\ImportStateRepository;
use KonradMichalik\Typo3PageMetrics\Provider\MetricProviderInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * DataFreshnessResolver.
 *
 * How current a site's numbers are, across every provider feeding it.
 *
 * The earliest of their days, not the latest: a row of tiles is only as
 * current as its oldest number, and reporting the freshest provider's day for
 * the whole row would hide a second provider that stopped a week ago. A
 * provider that has never run contributes no day at all, since its tiles are
 * empty anyway and letting it decide would hide how fresh the working one is.
 *
 * Read from the import's own resume cursor rather than from the covered
 * window: every run records the cursor, while the window can legitimately be
 * unknown on a healthy installation, and saying nothing about the data's age
 * is exactly the wrong answer to give about a scheduler that may have stopped.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DataFreshnessResolver
{
    /**
     * @param iterable<MetricProviderInterface> $providers
     */
    public function __construct(
        private ImportStateRepository $importStateRepository,
        private iterable $providers,
    ) {}

    public function forSite(Site $site, DateTimeImmutable $today): ?DataFreshness
    {
        $earliest = null;

        foreach ($this->providers as $provider) {
            if (!$provider->supports($site)) {
                continue;
            }

            $lastImported = $this->importStateRepository->getLastImportedDate($provider->getIdentifier(), $site->getIdentifier());

            if (null === $lastImported) {
                continue;
            }

            $earliest = null === $earliest ? $lastImported : min($earliest, $lastImported);
        }

        return null !== $earliest ? new DataFreshness($earliest, $today) : null;
    }
}
