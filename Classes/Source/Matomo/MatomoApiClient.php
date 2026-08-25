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

namespace KonradMichalik\Typo3PageMetrics\Source\Matomo;

use KonradMichalik\Typo3PageMetrics\Exception\MatomoRequestFailedException;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Thin hand-rolled Matomo Reporting API client. Requests are sent as POST so
 * the token never appears in URLs or access logs.
 *
 * Not final: Task 11 mocks this as a MatomoMetricSource collaborator in a
 * pure unit test, and PHP cannot generate a test double for a final class.
 */
class MatomoApiClient
{
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    /**
     * @param array<string, int|string> $parameters
     *
     * @return array<mixed>
     */
    public function request(MatomoConfiguration $configuration, array $parameters): array
    {
        $body = array_merge($parameters, [
            'module' => 'API',
            'idSite' => $configuration->idSite,
            'format' => 'JSON',
            'token_auth' => $configuration->token,
        ]);

        $url = rtrim($configuration->instanceUrl, '/').'/index.php';

        try {
            $response = $this->requestFactory->request($url, 'POST', [
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::TIMEOUT_SECONDS,
                'form_params' => $body,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $exception) {
            throw new MatomoRequestFailedException(sprintf('Matomo request failed: %s', $exception->getMessage()), 1756000100, $exception);
        }

        if (200 !== $response->getStatusCode()) {
            throw new MatomoRequestFailedException(sprintf('Matomo responded with HTTP %d.', $response->getStatusCode()), 1756000101);
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MatomoRequestFailedException('Matomo returned a non-JSON response.', 1756000102, $exception);
        }

        if (is_array($decoded) && 'error' === ($decoded['result'] ?? null)) {
            throw new MatomoRequestFailedException(sprintf('Matomo API error: %s', (string) ($decoded['message'] ?? 'unknown')), 1756000103);
        }

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }
}
