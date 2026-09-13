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

use function is_readable;
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
     * The byte offsets the private reader tracks are not exposed, because
     * nothing resumes from one: a per-file resume was measured and rejected
     * (#42), since on a rotated set the single plain file an offset applies
     * to is 0.02s of a 2.35s re-read while the gzip archives it cannot help
     * are 2.33s of it. The offsets stay because the reader needs them to walk
     * the file; they are not API.
     *
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
    private function readLinesWithOffset(string $path, int $fromOffset = 0): Generator
    {
        $isGzip = $this->isGzip($path);
        $handle = $this->open($path, $isGzip);

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

    /**
     * The is_readable() guard is what keeps a missing or unreadable path
     * from raising a native PHP warning, which is why the open call itself
     * needs no "@" suppression. The false check behind it stays as the
     * defensive fallback for the residual race: the file can still
     * disappear between the check and the open.
     *
     * @return resource
     */
    private function open(string $path, bool $isGzip): mixed
    {
        if (!is_readable($path)) {
            throw new RuntimeException(sprintf('Unable to open log file: %s', $path), 2881245831);
        }

        $handle = $isGzip ? gzopen($path, 'rb') : fopen($path, 'r');

        if (false === $handle) {
            throw new RuntimeException(sprintf('Unable to open log file: %s', $path), 4805609342);
        }

        return $handle;
    }

    private function isGzip(string $path): bool
    {
        if (!is_readable($path)) {
            return false;
        }

        $handle = fopen($path, 'r');
        if (false === $handle) {
            return false;
        }

        $magic = fread($handle, 2);
        fclose($handle);

        return "\x1f\x8b" === $magic;
    }
}
