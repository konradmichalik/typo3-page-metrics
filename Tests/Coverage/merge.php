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

/*
 * Merges the unit and functional coverage into one figure.
 *
 * Neither suite alone is meaningful: the unit tests cannot touch the
 * repositories or widgets, the functional tests do not exercise the pure
 * value objects and API clients. Only their union describes the extension,
 * so `composer test:coverage` runs both with --coverage-php and hands the
 * two serialised CodeCoverage objects to this script.
 *
 * Writes a merged Clover report plus the shields.io endpoint JSON backing
 * the README badge, and prints every file that is not fully covered.
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;

require __DIR__.'/../../vendor/autoload.php';

$coverageDir = __DIR__.'/../../.Build/coverage';
$inputs = [$coverageDir.'/unit.cov', $coverageDir.'/functional.cov'];

foreach ($inputs as $input) {
    if (!is_file($input)) {
        fwrite(\STDERR, sprintf("Missing %s - run `composer test:coverage` rather than this script directly.\n", $input));

        exit(1);
    }
}

$coverage = null;

foreach ($inputs as $input) {
    /** @var CodeCoverage $partial */
    $partial = require $input;

    if (!$coverage instanceof CodeCoverage) {
        $coverage = $partial;

        continue;
    }

    $coverage->merge($partial);
}

if (!$coverage instanceof CodeCoverage) {
    fwrite(\STDERR, "No coverage data collected.\n");

    exit(1);
}

(new Clover())->process($coverage, $coverageDir.'/clover.xml');

$executable = 0;
$executed = 0;
$incomplete = [];

foreach ($coverage->getData()->lineCoverage() as $file => $lines) {
    if (!str_contains($file, '/Classes/')) {
        continue;
    }

    $fileExecutable = 0;
    $fileExecuted = 0;
    $missing = [];

    foreach ($lines as $line => $coveringTests) {
        if (null === $coveringTests) {
            // Not an executable line (or excluded via @codeCoverageIgnore).
            continue;
        }

        ++$fileExecutable;

        if ([] === $coveringTests) {
            $missing[] = $line;

            continue;
        }

        ++$fileExecuted;
    }

    if (0 === $fileExecutable) {
        continue;
    }

    $executable += $fileExecutable;
    $executed += $fileExecuted;

    if ([] !== $missing) {
        $incomplete[substr($file, strpos($file, '/Classes/') + 9)] = $missing;
    }
}

$percent = 0 === $executable ? 100.0 : round($executed / $executable * 100, 2);

// shields.io endpoint schema, see https://shields.io/badges/endpoint-badge
file_put_contents($coverageDir.'/badge.json', json_encode([
    'schemaVersion' => 1,
    'label' => 'coverage',
    'message' => sprintf('%s%%', rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.')),
    'color' => match (true) {
        $percent >= 95.0 => 'brightgreen',
        $percent >= 90.0 => 'green',
        $percent >= 75.0 => 'yellow',
        $percent >= 50.0 => 'orange',
        default => 'red',
    },
], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT)."\n");

printf("Lines: %.2f%% (%d/%d)\n", $percent, $executed, $executable);

ksort($incomplete);

foreach ($incomplete as $file => $missing) {
    printf("  %-58s missing: %s\n", $file, implode(',', $missing));
}

printf("\n%s\n", [] === $incomplete ? 'Every file under Classes/ is fully covered.' : sprintf('%d file(s) not fully covered.', count($incomplete)));
