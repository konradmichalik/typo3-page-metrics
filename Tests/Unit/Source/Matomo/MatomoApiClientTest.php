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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Source\Matomo;

use KonradMichalik\Typo3PageMetrics\Exception\MatomoRequestFailedException;
use KonradMichalik\Typo3PageMetrics\Source\Matomo\{MatomoApiClient, MatomoConfiguration};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class MatomoApiClientTest extends TestCase
{
    /** @var RequestFactory&\PHPUnit\Framework\MockObject\MockObject */
    private RequestFactory $requestFactory;
    private MatomoApiClient $subject;
    private MatomoConfiguration $configuration;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->subject = new MatomoApiClient($this->requestFactory);
        $this->configuration = new MatomoConfiguration('https://matomo.example.com/', 3, 'token123');
    }

    public function testRequestReturnsDecodedJsonBody(): void
    {
        $response = $this->jsonResponse(200, ['value' => 'ok']);
        $this->requestFactory->method('request')->willReturn($response);

        $result = $this->subject->request($this->configuration, ['method' => 'Actions.getPageUrls']);

        self::assertSame(['value' => 'ok'], $result);
    }

    public function testRequestThrowsOnNonSuccessStatusCode(): void
    {
        $response = $this->jsonResponse(500, ['value' => 'error']);
        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(MatomoRequestFailedException::class);

        $this->subject->request($this->configuration, ['method' => 'Actions.getPageUrls']);
    }

    public function testRequestThrowsOnMatomoErrorPayload(): void
    {
        $response = $this->jsonResponse(200, ['result' => 'error', 'message' => 'Invalid token']);
        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(MatomoRequestFailedException::class);

        $this->subject->request($this->configuration, ['method' => 'Actions.getPageUrls']);
    }

    public function testRequestNeverPutsTheAuthTokenInTheUrl(): void
    {
        $capturedUri = null;
        $this->requestFactory->method('request')->willReturnCallback(
            function (string $uri, string $method, array $options) use (&$capturedUri): ResponseInterface {
                $capturedUri = $uri;

                return $this->jsonResponse(200, ['value' => 'ok']);
            }
        );

        $this->subject->request($this->configuration, ['method' => 'Actions.getPageUrls']);

        self::assertIsString($capturedUri);
        self::assertStringNotContainsString($this->configuration->token, $capturedUri);
        self::assertSame('https://matomo.example.com/index.php', $capturedUri);
    }

    public function testRequestSendsTheAuthTokenInThePostBody(): void
    {
        $capturedOptions = null;
        $this->requestFactory->method('request')->willReturnCallback(
            function (string $uri, string $method, array $options) use (&$capturedOptions): ResponseInterface {
                $capturedOptions = $options;

                return $this->jsonResponse(200, ['value' => 'ok']);
            }
        );

        $this->subject->request($this->configuration, ['method' => 'Actions.getPageUrls']);

        self::assertIsArray($capturedOptions);
        self::assertArrayHasKey('form_params', $capturedOptions);
        self::assertSame($this->configuration->token, $capturedOptions['form_params']['token_auth'] ?? null);
    }

    public function testRequestBuildsExpectedUrlMethodAndFormBody(): void
    {
        $capturedUri = null;
        $capturedMethod = null;
        $capturedOptions = null;
        $this->requestFactory->method('request')->willReturnCallback(
            function (string $uri, string $method, array $options) use (&$capturedUri, &$capturedMethod, &$capturedOptions): ResponseInterface {
                $capturedUri = $uri;
                $capturedMethod = $method;
                $capturedOptions = $options;

                return $this->jsonResponse(200, ['value' => 'ok']);
            }
        );

        $this->subject->request($this->configuration, ['method' => 'Actions.getPageUrls', 'period' => 'day']);

        self::assertSame('https://matomo.example.com/index.php', $capturedUri);
        self::assertSame('POST', $capturedMethod);
        self::assertIsArray($capturedOptions);
        self::assertSame([
            'method' => 'Actions.getPageUrls',
            'period' => 'day',
            'module' => 'API',
            'idSite' => 3,
            'format' => 'JSON',
            'token_auth' => 'token123',
        ], $capturedOptions['form_params'] ?? null);
    }

    /**
     * @param array<mixed> $payload
     */
    private function jsonResponse(int $statusCode, array $payload): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn(\GuzzleHttp\Psr7\Utils::streamFor(json_encode($payload, \JSON_THROW_ON_ERROR)));

        return $response;
    }
}
