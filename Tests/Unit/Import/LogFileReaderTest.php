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

use function bin2hex;
use function random_bytes;
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
        $this->tmpDir = sys_get_temp_dir().'/pagemetrics-logfilereader-'.bin2hex(random_bytes(8));
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
    public function resumesAPlainFileFromAByteOffset(): void
    {
        // Driven through readLines() rather than the offsets it tracks
        // internally: the resume is the behaviour, and the byte positions are
        // how it is reached rather than something a caller reads.
        $path = $this->tmpDir.'/access.log';
        file_put_contents($path, "line one\nline two\nline three\n");

        $lines = iterator_to_array($this->reader->readLines($path, strlen("line one\nline two\n")));

        self::assertSame(['line three'], $lines);
    }

    #[Test]
    public function resumesAGzipFileFromADecompressedOffset(): void
    {
        // The offset counts decompressed bytes, not bytes of the file on
        // disk, which is the only reading that survives the archive being
        // recompressed at a different level.
        $path = $this->tmpDir.'/access.log-20260101';
        file_put_contents($path, gzencode("gzipped line one\ngzipped line two\n"));

        $lines = iterator_to_array($this->reader->readLines($path, strlen("gzipped line one\n")));

        self::assertSame(['gzipped line two'], $lines);
    }
}
