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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Source\Ga4;

use Firebase\JWT\{JWT, Key};
use KonradMichalik\Typo3PageMetrics\Exception\Ga4RequestFailedException;
use KonradMichalik\Typo3PageMetrics\Source\Ga4\{Ga4AuthTokenProvider, Ga4Configuration};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Ga4AuthTokenProviderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Ga4AuthTokenProviderTest extends TestCase
{
    /** @var RequestFactory&\PHPUnit\Framework\MockObject\MockObject */
    private RequestFactory $requestFactory;
    private Ga4AuthTokenProvider $subject;
    private Ga4Configuration $configuration;
    private string $publicKeyPem;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->subject = new Ga4AuthTokenProvider($this->requestFactory);

        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($keyPair);
        openssl_pkey_export($keyPair, $privateKeyPem);
        $details = openssl_pkey_get_details($keyPair);
        self::assertIsArray($details);
        $this->publicKeyPem = $details['key'];

        $this->configuration = new Ga4Configuration('123456789', 'test@example.iam.gserviceaccount.com', $privateKeyPem);
    }

    public function testGetAccessTokenSendsAWellFormedSignedJwtAssertion(): void
    {
        $capturedOptions = null;
        $this->requestFactory->method('request')->willReturnCallback(
            function (string $uri, string $method, array $options) use (&$capturedOptions): ResponseInterface {
                $capturedOptions = $options;

                return $this->jsonResponse(200, ['access_token' => 'fake-token', 'expires_in' => 3599, 'token_type' => 'Bearer']);
            },
        );

        $this->subject->getAccessToken($this->configuration);

        self::assertIsArray($capturedOptions);
        $assertion = $capturedOptions['form_params']['assertion'] ?? null;
        self::assertIsString($assertion);

        $claims = (array) JWT::decode($assertion, new Key($this->publicKeyPem, 'RS256'));
        self::assertSame('test@example.iam.gserviceaccount.com', $claims['iss']);
        self::assertSame('https://www.googleapis.com/auth/analytics.readonly', $claims['scope']);
        self::assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        self::assertSame($capturedOptions['form_params']['grant_type'] ?? null, 'urn:ietf:params:oauth:grant-type:jwt-bearer');
    }

    public function testGetAccessTokenReturnsTheAccessTokenFromTheResponse(): void
    {
        $this->requestFactory->method('request')->willReturn(
            $this->jsonResponse(200, ['access_token' => 'the-access-token', 'expires_in' => 3599, 'token_type' => 'Bearer']),
        );

        self::assertSame('the-access-token', $this->subject->getAccessToken($this->configuration));
    }

    public function testGetAccessTokenReusesACachedTokenWithinItsLifetime(): void
    {
        $this->requestFactory->expects(self::once())->method('request')->willReturn(
            $this->jsonResponse(200, ['access_token' => 'the-access-token', 'expires_in' => 3599, 'token_type' => 'Bearer']),
        );

        $first = $this->subject->getAccessToken($this->configuration);
        $second = $this->subject->getAccessToken($this->configuration);

        self::assertSame('the-access-token', $first);
        self::assertSame('the-access-token', $second);
    }

    public function testGetAccessTokenThrowsOnNonSuccessStatusCode(): void
    {
        $this->requestFactory->method('request')->willReturn($this->jsonResponse(401, ['error' => 'invalid_grant']));

        $this->expectException(Ga4RequestFailedException::class);

        $this->subject->getAccessToken($this->configuration);
    }

    public function testGetAccessTokenThrowsWhenResponseIsMissingAccessToken(): void
    {
        $this->requestFactory->method('request')->willReturn($this->jsonResponse(200, ['expires_in' => 3599]));

        $this->expectException(Ga4RequestFailedException::class);

        $this->subject->getAccessToken($this->configuration);
    }

    public function testGetAccessTokenThrowsWhenPrivateKeyIsMalformed(): void
    {
        $malformedConfiguration = new Ga4Configuration('123456789', 'test@example.iam.gserviceaccount.com', 'not-a-valid-pem-key');

        $this->expectException(Ga4RequestFailedException::class);

        $this->subject->getAccessToken($malformedConfiguration);
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
