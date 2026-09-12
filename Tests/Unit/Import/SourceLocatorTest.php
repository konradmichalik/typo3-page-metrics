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

use KonradMichalik\Typo3PageMetrics\Import\SourceLocator;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function random_bytes;

/**
 * SourceLocatorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(SourceLocator::class)]
final class SourceLocatorTest extends TestCase
{
    private string $tmpDir;

    private SourceLocator $locator;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/pagemetrics-sourcelocator-'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir);
        mkdir($this->tmpDir.'/subdir');
        $this->locator = new SourceLocator();
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->tmpDir.'/access.log',
            $this->tmpDir.'/access.log-20260101.gz',
            $this->tmpDir.'/notes.txt',
            $this->tmpDir.'/subdir/error.log',
            $this->tmpDir.'/custom-name.dat',
            $this->tmpDir.'/error.log',
        ] as $file) {
            if (is_file($file)) {
                chmod($file, 0o644);
                unlink($file);
            }
        }
        rmdir($this->tmpDir.'/subdir');
        rmdir($this->tmpDir);
    }

    #[Test]
    public function findsLogNamedFilesInADirectoryRecursively(): void
    {
        $access = $this->touch('access.log');
        $rotated = $this->touch('access.log-20260101.gz');
        $this->touch('notes.txt');
        $nested = $this->touch('subdir/error.log');

        $result = $this->locator->expand([$this->tmpDir]);

        self::assertSame([$access, $rotated, $nested], array_values(array_intersect([$access, $rotated, $nested], $result)));
        self::assertNotContains($this->tmpDir.'/notes.txt', $result);
    }

    #[Test]
    public function includesAnExplicitlyNamedFileEvenIfItDoesNotMatchTheLogNamePattern(): void
    {
        $custom = $this->touch('custom-name.dat');

        $result = $this->locator->expand([$custom]);

        self::assertSame([$custom], $result);
    }

    #[Test]
    public function silentlySkipsPathsThatDoNotExist(): void
    {
        $access = $this->touch('access.log');

        $result = $this->locator->expand([$access, $this->tmpDir.'/does-not-exist']);

        self::assertSame([$access], $result);
    }

    #[Test]
    public function deduplicatesAndSortsTheResult(): void
    {
        $access = $this->touch('access.log');

        $result = $this->locator->expand([$access, $this->tmpDir, $access]);

        self::assertSame(array_values(array_unique($result)), $result);
        $sorted = $result;
        sort($sorted);
        self::assertSame($sorted, $result);
    }

    #[Test]
    public function returnsAnEmptyListWhenNothingIsFound(): void
    {
        self::assertSame([], $this->locator->expand([$this->tmpDir.'/nope']));
    }

    #[Test]
    public function excludesAnExplicitlyNamedUnreadableFileButKeepsReadableOnes(): void
    {
        $readable = $this->touch('access.log');
        $unreadable = $this->touch('error.log');
        chmod($unreadable, 0o000);

        if (is_readable($unreadable)) {
            self::markTestSkipped('File permissions are not enforced in this environment (e.g. running as root); cannot verify unreadable-file exclusion.');
        }

        $result = $this->locator->expand([$readable, $unreadable]);

        self::assertSame([$readable], $result);
    }

    #[Test]
    public function excludesAnUnreadableFileFoundWhileScanningADirectory(): void
    {
        $readable = $this->touch('access.log');
        $unreadable = $this->touch('subdir/error.log');
        chmod($unreadable, 0o000);

        if (is_readable($unreadable)) {
            self::markTestSkipped('File permissions are not enforced in this environment (e.g. running as root); cannot verify unreadable-file exclusion.');
        }

        $result = $this->locator->expand([$this->tmpDir]);

        self::assertSame([$readable], $result);
    }

    private function touch(string $relativePath): string
    {
        $path = $this->tmpDir.'/'.$relativePath;
        file_put_contents($path, 'content');

        return $path;
    }
}
