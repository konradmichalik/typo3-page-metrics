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

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * MetricSourceLabelProvider.
 *
 * The provider identifier as the name a tile's popover shows for where its
 * numbers came from.
 *
 * The question only becomes interesting once a site configures two providers:
 * Matomo and an access log both count page views, both tiles are called "Page
 * views", and the two numbers differ for reasons that are entirely correct.
 * Without the source named, the row asserts a contradiction.
 *
 * Resolved here rather than declared on MetricProviderInterface, which would
 * be a new required method on an `@api` interface every third-party provider
 * implements. An identifier this extension's XLF does not name falls back to
 * itself: "plausible" is a worse label than "Plausible" and a much better one
 * than nothing. A third-party provider that wants its own spelling can add
 * the trans-unit through locallangXMLOverride.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MetricSourceLabelProvider
{
    private const LL = 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:';

    public function for(string $providerIdentifier): string
    {
        // sL() returns an empty string for a key it cannot resolve, which is
        // the only signal there is that this provider is not one of ours.
        $label = $this->getLanguageService()->sL(self::LL.'provider.'.$providerIdentifier);

        return '' !== $label ? $label : $providerIdentifier;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
