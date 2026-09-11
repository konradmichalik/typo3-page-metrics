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

use Generator;
use RuntimeException;

use function sprintf;

/**
 * LogFileReader.
 *
 * Reads a log file line by line, transparently decompressing gzip
 * content regardless of file extension (mittwald and similar hosts
 * rotate logs into extensionless or oddly-suffixed gzip files).
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LogFileReader
{
    /**
     * @return Generator<int, string>
     */
    public function readLines(string $path, int $fromOffset = 0): Generator
    {
        foreach ($this->readLinesWithOffset($path, $fromOffset) as [$line, $offset]) {
            yield $line;
        }
    }

    /**
     * @return Generator<int, array{0: string, 1: int}>
     */
    public function readLinesWithOffset(string $path, int $fromOffset = 0): Generator
    {
        $isGzip = $this->isGzip($path);
        $handle = $isGzip ? @gzopen($path, 'rb') : @fopen($path, 'r');

        if (false === $handle) {
            throw new RuntimeException(sprintf('Unable to open log file: %s', $path));
        }

        try {
            if ($fromOffset > 0) {
                $isGzip ? gzseek($handle, $fromOffset) : fseek($handle, $fromOffset);
            }

            while (false !== ($line = $isGzip ? gzgets($handle) : fgets($handle))) {
                $offset = $isGzip ? gztell($handle) : ftell($handle);
                yield [rtrim($line, "\r\n"), (int) $offset];
            }
        } finally {
            $isGzip ? gzclose($handle) : fclose($handle);
        }
    }

    private function isGzip(string $path): bool
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return false;
        }

        $magic = fread($handle, 2);
        fclose($handle);

        return "\x1f\x8b" === $magic;
    }
}
