<?php

declare(strict_types=1);

/*
 * This file is part of the "page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Source\Ga4;

use KonradMichalik\Typo3PageMetrics\Source\Ga4\Ga4ConfigurationResolver;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Ga4ConfigurationResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Ga4ConfigurationResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private Ga4ConfigurationResolver $subject;

    private string $keyFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(Ga4ConfigurationResolver::class);
        $this->keyFilePath = (string) tempnam(sys_get_temp_dir(), 'ga4-key-');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->keyFilePath)) {
            unlink($this->keyFilePath);
        }

        parent::tearDown();
    }

    public function testResolvesConfigurationFromAValidKeyFile(): void
    {
        file_put_contents($this->keyFilePath, json_encode([
            'client_email' => 'test@example.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nFAKE\n-----END PRIVATE KEY-----\n",
        ], \JSON_THROW_ON_ERROR));

        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'sources' => [
                    'ga4' => ['propertyId' => '123456789', 'serviceAccountKeyFilePath' => $this->keyFilePath],
                ],
            ],
        ]);

        $configuration = $this->subject->resolve($site);

        self::assertNotNull($configuration);
        self::assertSame('123456789', $configuration->propertyId);
        self::assertSame('test@example.iam.gserviceaccount.com', $configuration->clientEmail);
        self::assertSame("-----BEGIN PRIVATE KEY-----\nFAKE\n-----END PRIVATE KEY-----\n", $configuration->privateKey);
    }

    public function testReturnsNullWhenNothingIsConfigured(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.com/']);

        self::assertNull($this->subject->resolve($site));
    }

    public function testReturnsNullWhenPropertyIdIsMissing(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'sources' => [
                    'ga4' => ['serviceAccountKeyFilePath' => $this->keyFilePath],
                ],
            ],
        ]);

        self::assertNull($this->subject->resolve($site));
    }

    public function testReturnsNullWhenKeyFileDoesNotExist(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'sources' => [
                    'ga4' => ['propertyId' => '123456789', 'serviceAccountKeyFilePath' => '/nonexistent/key.json'],
                ],
            ],
        ]);

        self::assertNull($this->subject->resolve($site));
    }

    public function testReturnsNullWhenKeyFileIsMalformedJson(): void
    {
        file_put_contents($this->keyFilePath, 'not json');

        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'sources' => [
                    'ga4' => ['propertyId' => '123456789', 'serviceAccountKeyFilePath' => $this->keyFilePath],
                ],
            ],
        ]);

        self::assertNull($this->subject->resolve($site));
    }

    public function testReturnsNullWhenKeyFileIsMissingRequiredFields(): void
    {
        file_put_contents($this->keyFilePath, json_encode(['client_email' => 'test@example.com'], \JSON_THROW_ON_ERROR));

        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'pageMetrics' => [
                'sources' => [
                    'ga4' => ['propertyId' => '123456789', 'serviceAccountKeyFilePath' => $this->keyFilePath],
                ],
            ],
        ]);

        self::assertNull($this->subject->resolve($site));
    }
}
