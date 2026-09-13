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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Service;

use DateTimeImmutable;
use Generator;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{CsvExport, DateRange};
use KonradMichalik\Typo3PageMetrics\Service\CsvStreamWriter;
use PHPUnit\Framework\TestCase;

use function array_pop;
use function array_slice;
use function explode;
use function str_starts_with;
use function substr;

/**
 * CsvStreamWriterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class CsvStreamWriterTest extends TestCase
{
    private const BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    private CsvStreamWriter $subject;

    protected function setUp(): void
    {
        $this->subject = new CsvStreamWriter();
    }

    public function testLeadsWithAByteOrderMarkSoExcelReadsItAsUtf8(): void
    {
        self::assertTrue(str_starts_with($this->write(['metric'], [['x']]), self::BYTE_ORDER_MARK));
    }

    public function testSeparatesFieldsWithASemicolon(): void
    {
        self::assertSame('"metric";"value"', $this->lines(['metric', 'value'], [])[0]);
    }

    public function testEndsEveryRecordWithACarriageReturnAndLineFeed(): void
    {
        $csv = $this->body(['metric'], [['a'], ['b']]);

        self::assertSame("\"metric\"\r\n\"a\"\r\n\"b\"\r\n", $csv);
    }

    public function testWritesTheHeaderBeforeTheRows(): void
    {
        self::assertSame(['"metric";"value"', '"matomo.pageviews";183'], $this->lines(['metric', 'value'], [['matomo.pageviews', 183]]));
    }

    public function testATitleCarryingTheDelimiterSurvivesAsOneField(): void
    {
        self::assertSame('"Prices; and terms"', $this->rows([['Prices; and terms']])[0]);
    }

    public function testATitleCarryingAQuoteKeepsItDoubled(): void
    {
        self::assertSame('"The ""best"" page"', $this->rows([['The "best" page']])[0]);
    }

    public function testATitleCarryingANewlineStaysInsideItsField(): void
    {
        // Quoted, so an RFC 4180 reader keeps it in one cell rather than
        // reading the second half as another row.
        self::assertSame("\"page\"\r\n\"Two\nlines\"\r\n", $this->body(['page'], [["Two\nlines"]]));
    }

    /**
     * The reason this is a security test and not a formatting one: a page
     * title is editor input and lands in a file somebody opens in Excel.
     */
    public function testAFieldThatWouldBeAFormulaIsNeutralisedWithALeadingQuote(): void
    {
        $rows = [['=HYPERLINK("http://evil","click")'], ['+1+1'], ['-1+1'], ['@SUM(A1:A9)']];

        self::assertSame(
            ['"\'=HYPERLINK(""http://evil"",""click"")"', '"\'+1+1"', '"\'-1+1"', '"\'@SUM(A1:A9)"'],
            $this->rows($rows),
        );
    }

    public function testANegativeNumberIsNotMistakenForAFormula(): void
    {
        // The one case the escape must not touch, and the reason it does
        // not: the prefix skips anything is_numeric() recognises, so a
        // delta percent survives as a number whether it arrives as an int
        // or as the string an int becomes on the way into the record.
        self::assertSame('-42;-42;-0.5', $this->rows([[-42, '-42', '-0.5']])[0]);
    }

    public function testALoneMinusIsStillEscaped(): void
    {
        // Not a number, so nothing is lost by treating it as the text it is.
        self::assertSame('"\'-"', $this->rows([['-']])[0]);
    }

    public function testAnAbsentValueIsAnEmptyField(): void
    {
        self::assertSame('"metric";', $this->rows([['metric', null]])[0]);
    }

    public function testConsumesRowsFromAGeneratorWithoutBeingHandedAnArray(): void
    {
        $rows = (static function (): Generator {
            yield ['a'];
            yield ['b'];
        })();

        self::assertSame(['"a"', '"b"'], $this->rows($rows));
    }

    public function testTheStreamIsHandedBackReadyToBeRead(): void
    {
        $stream = $this->subject->write($this->export(['metric'], []));

        self::assertSame(0, $stream->tell());
        self::assertNotSame('', $stream->getContents());
    }

    /**
     * @param list<string>                    $header
     * @param iterable<list<string|int|null>> $rows
     */
    private function export(array $header, iterable $rows): CsvExport
    {
        return CsvExport::named('page', 'page-1', new DateRange(new DateTimeImmutable('2026-08-15'), new DateTimeImmutable('2026-09-13')), $header, $rows);
    }

    /**
     * @param list<string>                    $header
     * @param iterable<list<string|int|null>> $rows
     */
    private function write(array $header, iterable $rows): string
    {
        return (string) $this->subject->write($this->export($header, $rows));
    }

    /**
     * @param list<string>                    $header
     * @param iterable<list<string|int|null>> $rows
     */
    private function body(array $header, iterable $rows): string
    {
        return substr($this->write($header, $rows), 3);
    }

    /**
     * @param list<string>                    $header
     * @param iterable<list<string|int|null>> $rows
     *
     * @return list<string>
     */
    private function lines(array $header, iterable $rows): array
    {
        $lines = explode("\r\n", $this->body($header, $rows));
        array_pop($lines);

        return $lines;
    }

    /**
     * The records without the header, for the assertions that are about a
     * field rather than about the column names above it.
     *
     * @param iterable<list<string|int|null>> $rows
     *
     * @return list<string>
     */
    private function rows(iterable $rows): array
    {
        return array_slice($this->lines(['header'], $rows), 1);
    }
}
