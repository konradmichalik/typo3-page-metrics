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

namespace KonradMichalik\Typo3PageMetrics\Source\Ga4;

use JsonException;
use KonradMichalik\Typo3PageMetrics\Exception\Ga4RequestFailedException;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;

use function is_array;
use function json_decode;
use function sprintf;

/**
 * Ga4ApiClient.
 *
 * Thin client for the GA4 Data API's runReport endpoint. Requests are sent
 * as JSON POST bodies with a Bearer access token from Ga4AuthTokenProvider.
 *
 * Not final: Task 4 mocks this as a Ga4MetricSource collaborator in a pure
 * unit test, and PHP cannot generate a test double for a final class.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class Ga4ApiClient
{
    private const API_BASE_URL = 'https://analyticsdata.googleapis.com/v1beta';
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly Ga4AuthTokenProvider $authTokenProvider,
    ) {}

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function runReport(Ga4Configuration $configuration, array $body): array
    {
        $token = $this->authTokenProvider->getAccessToken($configuration);
        $url = sprintf('%s/properties/%s:runReport', self::API_BASE_URL, $configuration->propertyId);

        try {
            $response = $this->requestFactory->request($url, 'POST', [
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ],
                'json' => $body,
            ]);
        } catch (Throwable $exception) {
            throw new Ga4RequestFailedException(sprintf('GA4 request failed: %s', $exception->getMessage()), 1756200200, $exception);
        }

        if (200 !== $response->getStatusCode()) {
            throw new Ga4RequestFailedException(sprintf('GA4 responded with HTTP %d.', $response->getStatusCode()), 1756200201);
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new Ga4RequestFailedException('GA4 returned a non-JSON response.', 1756200202, $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
