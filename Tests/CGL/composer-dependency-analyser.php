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

use ShipMonk\ComposerDependencyAnalyser\Config\{Configuration, ErrorType};

$root = __DIR__.'/../..';

return (new Configuration())
    // The root composer.json's autoload-dev maps the whole of Tests/ - which
    // contains this directory's own vendor/ with the CGL toolchain. Deriving the
    // scan paths from autoload would pull those ~6800 files in and report every
    // library they use as a shadow dependency, so the paths are listed by hand.
    ->disableComposerAutoloadPathScan()
    ->addPathToScan($root.'/Classes', false)
    ->addPathToScan($root.'/Configuration', false)
    ->addPathToScan($root.'/Tests/Unit', true)
    ->addPathToScan($root.'/Tests/Functional', true)
    // EXT:dashboard is optional by design: Configuration/Services.yaml excludes
    // Classes/Widgets/* from autowiring and Configuration/Services.php registers
    // the widgets only when WidgetRegistry is in the container. So the widget
    // classes reference cms-dashboard interfaces while the package stays a
    // require-dev + suggest entry - installations without EXT:dashboard never
    // load them.
    ->ignoreErrorsOnPackage('typo3/cms-dashboard', [ErrorType::DEV_DEPENDENCY_IN_PROD])
;
