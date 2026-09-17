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

namespace KonradMichalik\Typo3PageMetrics\Domain\Enum;

/**
 * AnnotationCategory.
 *
 * What kind of event a marker stands for, so a chart can say that much
 * without being hovered.
 *
 * A closed enum rather than free-text tags. A fixed set can carry its own
 * icon and its own colour, and the experience every tagged annotation system
 * ends up reporting is that free text drifts in spelling until filtering by
 * it is worthless: "Release", "release" and "Deployment" become three
 * categories describing one thing.
 *
 * The icon lives here because it is a property of the category. A colour
 * does not, and no longer exists: a marker takes the colour of the surface
 * it is drawn on, and the kind of event is carried in words instead.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
enum AnnotationCategory: string
{
    case Release = 'release';
    case Campaign = 'campaign';
    case Content = 'content';
    case Redesign = 'redesign';
    case Incident = 'incident';
    case Seo = 'seo';
    case External = 'external';

    /**
     * The stored value, or the default, for anything this extension did not
     * write itself. A row predating the column, or a hand-edited value, is a
     * marker whose kind is simply unknown, and "release" is what every
     * annotation in this extension meant before categories existed.
     */
    public static function fromStorage(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Release;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Release => 'tabler-rocket',
            self::Campaign => 'tabler-speakerphone',
            self::Content => 'tabler-pencil',
            self::Redesign => 'tabler-layout-dashboard',
            self::Incident => 'tabler-alert-triangle',
            self::Seo => 'tabler-world-search',
            self::External => 'tabler-external-link',
        };
    }

    public function label(): string
    {
        return 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_mod.xlf:annotation.category.'.$this->value;
    }
}
