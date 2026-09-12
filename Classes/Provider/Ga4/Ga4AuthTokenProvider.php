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

namespace KonradMichalik\Typo3PageMetrics\Provider\Ga4;

use Firebase\JWT\JWT;
use JsonException;
use KonradMichalik\Typo3PageMetrics\Exception\Ga4RequestFailedException;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;

use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function sprintf;
use function time;

/**
 * Ga4AuthTokenProvider.
 *
 * Hand-rolled OAuth2 service-account JWT-Bearer flow (RFC 7523): signs a
 * short-lived assertion with the service account's own private key and
 * exchanges it at Google's token endpoint for a Bearer access token. No
 * google/apiclient dependency - firebase/php-jwt only does the signing.
 *
 * Not final: Task 3 mocks this as a Ga4ApiClient collaborator in a pure
 * unit test, and PHP cannot generate a test double for a final class.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class Ga4AuthTokenProvider
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
    private const EXPIRY_SAFETY_MARGIN_SECONDS = 60;
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 15;

    /** @var array<string, array{token: string, expiresAt: int}> */
    private array $cache = [];

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function getAccessToken(Ga4Configuration $configuration): string
    {
        $cacheKey = $configuration->clientEmail.'|'.$configuration->propertyId;
        $cached = $this->cache[$cacheKey] ?? null;

        if (null !== $cached && $cached['expiresAt'] > (time() + self::EXPIRY_SAFETY_MARGIN_SECONDS)) {
            return $cached['token'];
        }

        $token = $this->requestNewToken($configuration);
        $this->cache[$cacheKey] = $token;

        return $token['token'];
    }

    /**
     * @return array{token: string, expiresAt: int}
     */
    private function requestNewToken(Ga4Configuration $configuration): array
    {
        $now = time();

        try {
            $assertion = JWT::encode([
                'iss' => $configuration->clientEmail,
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_ENDPOINT,
                'iat' => $now,
                'exp' => $now + 3600,
            ], $configuration->privateKey, 'RS256');
        } catch (Throwable $exception) {
            throw new Ga4RequestFailedException(sprintf('GA4 JWT assertion could not be signed: %s', $exception->getMessage()), 1756200099, $exception);
        }

        try {
            $response = $this->requestFactory->request(self::TOKEN_ENDPOINT, 'POST', [
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::TIMEOUT_SECONDS,
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ],
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (Throwable $exception) {
            throw new Ga4RequestFailedException(sprintf('GA4 authentication request failed: %s', $exception->getMessage()), 1756200100, $exception);
        }

        if (200 !== $response->getStatusCode()) {
            throw new Ga4RequestFailedException(sprintf('GA4 authentication responded with HTTP %d.', $response->getStatusCode()), 1756200101);
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new Ga4RequestFailedException('GA4 authentication returned a non-JSON response.', 1756200102, $exception);
        }

        if (!is_array($decoded) || !is_string($decoded['access_token'] ?? null) || '' === $decoded['access_token'] || !is_numeric($decoded['expires_in'] ?? null)) {
            throw new Ga4RequestFailedException('GA4 authentication response is missing access_token/expires_in.', 1756200103);
        }

        return ['token' => $decoded['access_token'], 'expiresAt' => $now + (int) $decoded['expires_in']];
    }
}
