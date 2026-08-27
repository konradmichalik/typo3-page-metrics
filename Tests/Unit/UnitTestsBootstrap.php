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

require __DIR__.'/../../vendor/autoload.php';

$projectPath = sys_get_temp_dir().'/typo3-page-metrics-unit-tests';

foreach (['', '/public', '/var', '/config'] as $directory) {
    if (!is_dir($projectPath.$directory)) {
        mkdir($projectPath.$directory, 0o700, true);
    }
}

TYPO3\CMS\Core\Core\Environment::initialize(
    new TYPO3\CMS\Core\Core\ApplicationContext('Testing'),
    true,
    true,
    $projectPath,
    $projectPath.'/public',
    $projectPath.'/var',
    $projectPath.'/config',
    $projectPath.'/public/index.php',
    'Windows' === \PHP_OS_FAMILY ? 'WINDOWS' : 'UNIX',
);
