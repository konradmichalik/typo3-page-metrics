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

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\Annotation;

/**
 * AnnotationPayloadBuilder.
 *
 * The shape sparkline.js reads out of a data-annotations attribute. The same
 * three consumers that need SparklineLabelProvider's strings need this too
 * (the page-header AJAX route and the two dashboard widgets that render
 * sparkline markup), and it exists for the same reason: written out at each
 * of them, a field added here would reach one surface and quietly not the
 * other two.
 *
 * Dates go over as 'Y-m-d' strings and the category as its bare value,
 * because that is what the client compares against - json_encode would
 * otherwise hand it a DateTimeImmutable's three-field object and an enum's
 * name/value pair, neither of which it can match a day against.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class AnnotationPayloadBuilder
{
    /**
     * @param list<Annotation> $annotations
     *
     * @return list<array{date: string, dateUntil: ?string, category: string, text: string}>
     */
    public function build(array $annotations): array
    {
        return array_map(
            static fn (Annotation $annotation): array => [
                'date' => $annotation->date->format('Y-m-d'),
                'dateUntil' => $annotation->dateUntil?->format('Y-m-d'),
                'category' => $annotation->category->value,
                'text' => $annotation->text,
            ],
            $annotations,
        );
    }
}
