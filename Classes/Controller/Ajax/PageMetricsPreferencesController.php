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

namespace KonradMichalik\Typo3PageMetrics\Controller\Ajax;

use KonradMichalik\Typo3PageMetrics\Service\{PageMetricsAccessChecker, PageMetricsUserPreferences};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Core\Http\JsonResponse;

use function is_array;

/**
 * PageMetricsPreferencesController.
 *
 * Stores the editor's own view preferences, currently just whether the tiles
 * are folded away. Its own route rather than a mode on the tiles route: that
 * one is a GET that returns markup and is safe to repeat, this one is a POST
 * that changes stored state, and giving a read endpoint a side effect is how
 * caches and prefetchers end up rewriting user settings.
 *
 * Guarded by the same PageMetricsAccessChecker as the tiles route, so a user
 * an administrator has switched the feature off for cannot store a
 * preference about it either. CSRF is handled by the backend route itself
 * (see Configuration/Backend/AjaxRoutes.php): every ajax_* route requires a
 * valid token, which the client sends with the URI the UriBuilder produced.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageMetricsPreferencesController
{
    public function __construct(
        private PageMetricsAccessChecker $accessChecker,
        private PageMetricsUserPreferences $preferences,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->accessChecker->hasAccess()) {
            return new JsonResponse(['success' => false], 403);
        }

        $body = $request->getParsedBody();
        $collapsed = is_array($body) ? ($body['collapsed'] ?? null) : null;

        if (null === $collapsed) {
            return new JsonResponse(['success' => false], 400);
        }

        $this->preferences->setCollapsed('1' === (string) $collapsed);

        return new JsonResponse(['success' => true]);
    }
}
