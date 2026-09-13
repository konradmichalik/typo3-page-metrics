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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Provider;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\TrafficChannel;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\UrlMetric;
use KonradMichalik\Typo3PageMetrics\Provider\Ga4\Ga4ChannelMetrics;
use KonradMichalik\Typo3PageMetrics\Provider\Matomo\MatomoChannelMetrics;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_unique;
use function array_values;
use function iterator_to_array;
use function sort;

/**
 * TrafficChannelVocabularyTest.
 *
 * TrafficChannel holds the shared vocabulary, so what the type system cannot
 * state is only the folding: that each provider's own referrer types end up
 * inside that set, and that a site switching from Matomo to GA4 does not
 * silently start calling the same traffic by a different name. The expected
 * set is read off the enum rather than restated, so adding a case cannot
 * leave this test asserting yesterday's vocabulary.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TrafficChannelVocabularyTest extends TestCase
{
    public function testMatomoWritesOnlySharedChannelNames(): void
    {
        self::assertSame([], array_diff($this->matomoChannels(), self::vocabulary()));
    }

    public function testGa4WritesOnlySharedChannelNames(): void
    {
        self::assertSame([], array_diff($this->ga4Channels(), self::vocabulary()));
    }

    public function testEveryChannelMatomoKnowsIsOneGa4AlsoProduces(): void
    {
        // Not the other way round: GA4 has an "Unassigned" group and Matomo
        // classifies every visit, so channel:other is the one name only one
        // of the two ever writes.
        self::assertSame([], array_diff($this->matomoChannels(), $this->ga4Channels()));
    }

    /**
     * @return list<string>
     */
    private static function vocabulary(): array
    {
        return array_map(static fn (TrafficChannel $channel): string => $channel->dimension(), TrafficChannel::cases());
    }

    /**
     * @return list<string>
     */
    private function matomoChannels(): array
    {
        $response = [];

        // One page row per sub-request slot, so every segment the bulk
        // request asks about comes back with something in it.
        for ($slot = 0; $slot < 16; ++$slot) {
            $response[] = [['url' => 'https://example.com/about', 'nb_visits' => 1]];
        }

        return $this->dimensionsOf(iterator_to_array((new MatomoChannelMetrics())->metricsFor($response, new DateTimeImmutable('2026-08-01')), false));
    }

    /**
     * @return list<string>
     */
    private function ga4Channels(): array
    {
        $rows = [];

        // Every group the GA4 Data API documents for
        // sessionDefaultChannelGroup, plus one it does not, because Google
        // adds them over time.
        foreach ([
            'Direct', 'Organic Search', 'Paid Search', 'Organic Social', 'Paid Social', 'Referral',
            'Organic Video', 'Paid Video', 'Organic Shopping', 'Paid Shopping', 'Display', 'Email',
            'Affiliates', 'Audio', 'SMS', 'Mobile Push Notifications', 'Cross-network', 'Unassigned',
            'Some Future Channel',
        ] as $group) {
            $rows[] = [
                'dimensionValues' => [['value' => '20260801'], ['value' => '/about'], ['value' => $group]],
                'metricValues' => [['value' => '1']],
            ];
        }

        return $this->dimensionsOf(iterator_to_array((new Ga4ChannelMetrics())->metricsFor('https://example.com', $rows), false));
    }

    /**
     * @param list<UrlMetric> $metrics
     *
     * @return list<string>
     */
    private function dimensionsOf(array $metrics): array
    {
        $dimensions = array_values(array_unique(array_map(static fn (UrlMetric $metric): string => (string) $metric->dimension, $metrics)));
        sort($dimensions);

        return $dimensions;
    }
}
