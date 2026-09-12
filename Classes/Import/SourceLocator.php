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

namespace KonradMichalik\Typo3PageMetrics\Import;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * SourceLocator.
 *
 * Expands file and directory arguments into a concrete, sorted list of
 * log files. Directories are scanned recursively and filtered by
 * filename; explicitly named files are trusted as-is, regardless of
 * their name. Paths that don't exist are silently skipped, matching the
 * reference `ai-log-probe.php`'s behavior — a missing archive directory
 * on one host shouldn't abort an import that covers several hosts.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SourceLocator
{
    private const LOG_FILENAME_PATTERN = '/(^|[._-])(access|error|varnish)([._-]|$)|\.log($|\.)/i';

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public function expand(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path) && is_readable($path)) {
                $files[] = $path;
                continue;
            }

            if (is_dir($path)) {
                array_push($files, ...$this->scanDirectory($path));
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function scanDirectory(string $directory): array
    {
        $found = [];

        /** @var iterable<string, SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->isReadable() && 1 === preg_match(self::LOG_FILENAME_PATTERN, $fileInfo->getFilename())) {
                $found[] = $fileInfo->getPathname();
            }
        }

        return $found;
    }
}
