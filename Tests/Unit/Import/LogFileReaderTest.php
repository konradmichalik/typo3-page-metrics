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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Import;

use KonradMichalik\Typo3PageMetrics\Import\LogFileReader;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function strlen;

/**
 * LogFileReaderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(LogFileReader::class)]
final class LogFileReaderTest extends TestCase
{
    private string $tmpDir;

    private LogFileReader $reader;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/pagemetrics-logfilereader-'.uniqid();
        mkdir($this->tmpDir);
        $this->reader = new LogFileReader();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    #[Test]
    public function readsLinesFromAPlainTextFile(): void
    {
        $path = $this->tmpDir.'/access.log';
        file_put_contents($path, "line one\nline two\nline three\n");

        $lines = iterator_to_array($this->reader->readLines($path));

        self::assertSame(['line one', 'line two', 'line three'], $lines);
    }

    #[Test]
    public function readsLinesFromAGzipFileRegardlessOfExtension(): void
    {
        $path = $this->tmpDir.'/access.log-20260101';
        file_put_contents($path, gzencode("gzipped line one\ngzipped line two\n"));

        $lines = iterator_to_array($this->reader->readLines($path));

        self::assertSame(['gzipped line one', 'gzipped line two'], $lines);
    }

    #[Test]
    public function yieldsNoLinesForAnEmptyFile(): void
    {
        $path = $this->tmpDir.'/empty.log';
        file_put_contents($path, '');

        $lines = iterator_to_array($this->reader->readLines($path));

        self::assertSame([], $lines);
    }

    #[Test]
    public function throwsWhenTheFileCannotBeOpened(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array($this->reader->readLines($this->tmpDir.'/does-not-exist.log'));
    }

    #[Test]
    public function defaultsToReadingFromTheStartWhenNoOffsetIsGiven(): void
    {
        $path = $this->tmpDir.'/access.log';
        file_put_contents($path, "line one\nline two\n");

        $lines = iterator_to_array($this->reader->readLines($path));

        self::assertSame(['line one', 'line two'], $lines);
    }

    #[Test]
    public function resumesFromAByteOffsetForAPlainFile(): void
    {
        $path = $this->tmpDir.'/access.log';
        file_put_contents($path, "line one\nline two\nline three\n");
        $offset = strlen("line one\n");

        $lines = iterator_to_array($this->reader->readLines($path, $offset));

        self::assertSame(['line two', 'line three'], $lines);
    }

    #[Test]
    public function resumesFromAByteOffsetForAGzipFile(): void
    {
        $path = $this->tmpDir.'/access.log-20260101';
        file_put_contents($path, gzencode("gzipped line one\ngzipped line two\n"));
        $offset = strlen("gzipped line one\n");

        $lines = iterator_to_array($this->reader->readLines($path, $offset));

        self::assertSame(['gzipped line two'], $lines);
    }

    #[Test]
    public function yieldsTheTrueStreamOffsetAfterEachLineForAPlainFile(): void
    {
        $path = $this->tmpDir.'/access.log';
        file_put_contents($path, "line one\nline two\nline three\n");

        $pairs = iterator_to_array($this->reader->readLinesWithOffset($path));

        self::assertSame([
            ['line one', strlen("line one\n")],
            ['line two', strlen("line one\nline two\n")],
            ['line three', strlen("line one\nline two\nline three\n")],
        ], $pairs);
    }

    #[Test]
    public function yieldsTheDecompressedStreamOffsetAfterEachLineForAGzipFile(): void
    {
        $path = $this->tmpDir.'/access.log-20260101';
        file_put_contents($path, gzencode("gzipped line one\ngzipped line two\n"));

        $pairs = iterator_to_array($this->reader->readLinesWithOffset($path));

        self::assertSame([
            ['gzipped line one', strlen("gzipped line one\n")],
            ['gzipped line two', strlen("gzipped line one\ngzipped line two\n")],
        ], $pairs);
    }
}
