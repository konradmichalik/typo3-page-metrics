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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Provider\Ga4;

use GuzzleHttp\Psr7\Utils;
use KonradMichalik\Typo3PageMetrics\Exception\Ga4RequestFailedException;
use KonradMichalik\Typo3PageMetrics\Provider\Ga4\{Ga4ApiClient, Ga4AuthTokenProvider, Ga4Configuration};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Ga4ApiClientTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Ga4ApiClientTest extends TestCase
{
    /** @var RequestFactory&\PHPUnit\Framework\MockObject\MockObject */
    private RequestFactory $requestFactory;

    /** @var Ga4AuthTokenProvider&\PHPUnit\Framework\MockObject\MockObject */
    private Ga4AuthTokenProvider $authTokenProvider;
    private Ga4ApiClient $subject;
    private Ga4Configuration $configuration;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->authTokenProvider = $this->createMock(Ga4AuthTokenProvider::class);
        $this->authTokenProvider->method('getAccessToken')->willReturn('the-access-token');
        $this->subject = new Ga4ApiClient($this->requestFactory, $this->authTokenProvider);
        $this->configuration = new Ga4Configuration('123456789', 'test@example.iam.gserviceaccount.com', 'fake-key');
    }

    public function testRunReportReturnsDecodedJsonBody(): void
    {
        $this->requestFactory->method('request')->willReturn($this->jsonResponse(200, ['rows' => []]));

        $result = $this->subject->runReport($this->configuration, ['dimensions' => []]);

        self::assertSame(['rows' => []], $result);
    }

    public function testRunReportBuildsExpectedUrlAndAuthorizationHeader(): void
    {
        $capturedUri = null;
        $capturedMethod = null;
        $capturedOptions = null;
        $this->requestFactory->method('request')->willReturnCallback(
            function (string $uri, string $method, array $options) use (&$capturedUri, &$capturedMethod, &$capturedOptions): ResponseInterface {
                $capturedUri = $uri;
                $capturedMethod = $method;
                $capturedOptions = $options;

                return $this->jsonResponse(200, ['rows' => []]);
            },
        );

        $this->subject->runReport($this->configuration, ['dimensions' => [['name' => 'date']]]);

        self::assertSame('https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport', $capturedUri);
        self::assertSame('POST', $capturedMethod);
        self::assertIsArray($capturedOptions);
        self::assertSame('Bearer the-access-token', $capturedOptions['headers']['Authorization'] ?? null);
        self::assertSame(['dimensions' => [['name' => 'date']]], $capturedOptions['json'] ?? null);
    }

    public function testRunReportThrowsOnNonSuccessStatusCode(): void
    {
        $this->requestFactory->method('request')->willReturn($this->jsonResponse(403, ['error' => 'forbidden']));

        $this->expectException(Ga4RequestFailedException::class);

        $this->subject->runReport($this->configuration, []);
    }

    public function testRunReportWrapsTransportFailuresInADomainException(): void
    {
        $this->requestFactory->method('request')->willThrowException(new RuntimeException('DNS failure'));

        $this->expectException(Ga4RequestFailedException::class);
        $this->expectExceptionCode(1756200200);
        $this->expectExceptionMessage('DNS failure');

        $this->subject->runReport($this->configuration, ['dimensions' => []]);
    }

    public function testRunReportThrowsWhenTheBodyIsNotJson(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(Utils::streamFor('<html>gateway timeout</html>'));
        $this->requestFactory->method('request')->willReturn($response);

        $this->expectException(Ga4RequestFailedException::class);
        $this->expectExceptionCode(1756200202);

        $this->subject->runReport($this->configuration, ['dimensions' => []]);
    }

    /**
     * @param array<mixed> $payload
     */
    private function jsonResponse(int $statusCode, array $payload): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn(Utils::streamFor(json_encode($payload, \JSON_THROW_ON_ERROR)));

        return $response;
    }
}
