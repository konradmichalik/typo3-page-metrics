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

namespace KonradMichalik\Typo3PageMetrics\Source\Matomo;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{Annotation, DateRange};
use KonradMichalik\Typo3PageMetrics\Source\AnnotationSourceInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * MatomoAnnotationSource.
 *
 * Calls Annotations.getAll once per range with period=range: Matomo's
 * response is keyed by idSite (even for a single site), so the row list
 * for the configured idSite is pulled out of that wrapper.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MatomoAnnotationSource implements AnnotationSourceInterface
{
    private const IDENTIFIER = 'matomo';

    public function __construct(
        private readonly MatomoConfigurationResolver $configurationResolver,
        private readonly MatomoApiClient $apiClient,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function supports(Site $site): bool
    {
        return null !== $this->configurationResolver->resolve($site);
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        $configuration = $this->configurationResolver->resolve($site);

        if (null === $configuration) {
            return;
        }

        $response = $this->apiClient->request($configuration, [
            'method' => 'Annotations.getAll',
            'period' => 'range',
            'date' => sprintf('%s,%s', $range->from->format('Y-m-d'), $range->until->format('Y-m-d')),
        ]);

        $rows = $response[(string) $configuration->idSite] ?? [];

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['date'] ?? null) || !is_string($row['note'] ?? null)) {
                continue;
            }

            $id = $row['id'] ?? $row['idNote'] ?? null;

            if (!is_string($id) && !is_int($id)) {
                continue;
            }

            yield new Annotation((string) $id, new DateTimeImmutable($row['date']), $row['note']);
        }
    }
}
