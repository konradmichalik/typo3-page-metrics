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

namespace KonradMichalik\Typo3PageMetrics\Service;

use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\CsvExport;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\CsvUtility;

use function array_map;
use function fopen;
use function is_resource;

/**
 * CsvStreamWriter.
 *
 * A CsvExport turned into bytes. The three choices it makes are all about
 * the one program the file is opened in, which is a spreadsheet and usually
 * Excel:
 *
 * - **Semicolon**, because Excel splits on the list separator of the machine
 *   it runs on and a comma-separated file lands in a single column on every
 *   German-locale installation, which is this extension's own audience.
 * - **UTF-8 with a byte order mark**, because without it Excel guesses the
 *   codepage and a page title with an umlaut in it arrives broken. It costs
 *   three bytes that a handful of strict parsers read as part of the first
 *   header field, and that is the trade: the named recipient is a person
 *   with a spreadsheet, not a parser.
 * - **CRLF**, which is what RFC 4180 says a record ends with.
 *
 * Nothing here concatenates. Every record goes through CsvUtility, so a
 * title containing the delimiter, a quote or a newline is quoted and escaped
 * by fputcsv rather than by hand, and TYPE_PREFIX_CONTROLS neutralises a
 * field that would otherwise be a formula when the file is opened: a page
 * title is editor input, and `=HYPERLINK(...)` in a cell is a request the
 * spreadsheet will honour. Numbers are exempt from that prefix because they
 * are passed as numbers, which is what keeps a delta percent of -42 from
 * becoming text.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CsvStreamWriter
{
    private const DELIMITER = ';';
    private const ENCLOSURE = '"';
    private const LINE_ENDING = "\r\n";
    private const BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    /**
     * Beyond this many bytes php://temp stops holding the file in memory and
     * spills it to disk on its own. That is what lets a site-wide export be
     * as long as the site without the request growing with it, and it is why
     * the rows arrive as an iterable: neither the rows nor the bytes are
     * ever all in memory at once.
     */
    private const MEMORY_BUDGET_BYTES = 1048576;

    public function write(CsvExport $export): StreamInterface
    {
        $resource = fopen('php://temp/maxmemory:'.self::MEMORY_BUDGET_BYTES, 'r+');

        if (!is_resource($resource)) {
            throw new RuntimeException('Cannot open a temporary stream for the CSV export.', 1757721600);
        }

        $stream = new Stream($resource, 'r+');
        $stream->write(self::BYTE_ORDER_MARK);
        $stream->write(self::record($export->header));

        foreach ($export->rows as $row) {
            $stream->write(self::record($row));
        }

        $stream->rewind();

        return $stream;
    }

    /**
     * Cast to string on the way in because that is the contract CsvUtility
     * declares, and it costs the numbers nothing: the control-literal prefix
     * skips anything is_numeric() recognises, so "-42" is left alone for the
     * same reason -42 is.
     *
     * @param list<string|int|null> $values
     */
    private static function record(array $values): string
    {
        $fields = array_map(static fn (string|int|null $value): string => (string) $value, $values);

        return CsvUtility::csvValues($fields, self::DELIMITER, self::ENCLOSURE, CsvUtility::TYPE_PREFIX_CONTROLS).self::LINE_ENDING;
    }
}
