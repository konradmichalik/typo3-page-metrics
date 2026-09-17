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

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Provider;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{MetricDefinition, RateMetricDefinition};
use KonradMichalik\Typo3PageMetrics\Provider\AccessLog\AccessLogMetricProvider;
use KonradMichalik\Typo3PageMetrics\Provider\Ga4\Ga4MetricProvider;
use KonradMichalik\Typo3PageMetrics\Provider\Matomo\MatomoMetricProvider;
use KonradMichalik\Typo3PageMetrics\Provider\MetricProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function sprintf;

/**
 * MetricDefinitionIconTest.
 *
 * The seam between the icon a provider names in a MetricDefinition and the
 * identifiers Configuration/Icons.php registers. It fails silently otherwise:
 * an unregistered identifier renders as an empty box rather than as an error,
 * and a bucket that only ever shows up inside the machine-traffic summary
 * would carry the fault unnoticed until something gives it a tile of its own.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MetricDefinitionIconTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    /**
     * @return iterable<string, array{0: class-string<MetricProviderInterface>}>
     */
    public static function providerClasses(): iterable
    {
        yield 'access log' => [AccessLogMetricProvider::class];
        yield 'matomo' => [MatomoMetricProvider::class];
        yield 'ga4' => [Ga4MetricProvider::class];
    }

    /**
     * @param class-string<MetricProviderInterface> $providerClass
     */
    #[DataProvider('providerClasses')]
    public function testEveryDeclaredIconIsRegistered(string $providerClass): void
    {
        $provider = $this->get($providerClass);
        self::assertInstanceOf(MetricProviderInterface::class, $provider);
        $iconRegistry = $this->get(IconRegistry::class);

        $icons = [
            ...array_map(static fn (MetricDefinition $definition): string => $definition->icon, $provider->getMetricDefinitions()),
            ...array_map(static fn (RateMetricDefinition $definition): string => $definition->icon, $provider->getRateMetricDefinitions()),
        ];

        self::assertNotSame([], $icons);

        foreach ($icons as $icon) {
            self::assertTrue(
                $iconRegistry->isRegistered($icon),
                sprintf('Icon "%s" is declared by %s but not registered in Configuration/Icons.php.', $icon, $providerClass),
            );
        }
    }

    public function testTheSummaryAndTheChatbotBucketDoNotShareAnIcon(): void
    {
        // They sit next to each other in the row and stand for different
        // questions. One icon on both reads as one tile shown twice.
        $icons = [];

        foreach ($this->get(AccessLogMetricProvider::class)->getMetricDefinitions() as $definition) {
            $icons[$definition->identifier] = $definition->icon;
        }

        // 'tabler-robot' is the machine-traffic summary's own icon, assembled
        // by PageMetricsViewDataBuilder rather than declared by a provider.
        self::assertNotSame('tabler-robot', $icons['bot.ai_chatbot']);
        self::assertNotSame('tabler-robot', $icons['bot.ai_crawler']);
        self::assertNotSame($icons['bot.ai_chatbot'], $icons['bot.ai_crawler']);
    }
}
