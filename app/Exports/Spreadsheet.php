<?php

namespace App\Exports;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A small builder for report workbooks: one or more sheets, each a title block, a bold
 * header and rows. Rupiah and quantity columns get real number formats, so Excel can sum
 * and chart them (unlike a CSV, which an Indonesian-locale Excel also splits wrongly).
 */
final class Spreadsheet
{
    public const RUPIAH = '"Rp" #,##0;-"Rp" #,##0';

    /** Average cost (HPP rata-rata), which keeps its decimals. */
    public const RUPIAH_CENTS = '"Rp" #,##0.00';

    public const NUMBER = '#,##0';

    public const PERCENT = '0.0"%"';

    /** @var list<array{name: string, title: list<string>, columns: list<array{label: string, format?: string, width?: float}>, rows: list<list<mixed>>, footer: list<mixed>|null}> */
    private array $sheets = [];

    /**
     * @param  list<string>  $title  lines above the table (report name, period, …)
     * @param  list<array{label: string, format?: string, width?: float}>  $columns
     * @param  list<list<mixed>>  $rows
     * @param  list<mixed>|null  $footer  a totals row
     */
    public function sheet(string $name, array $title, array $columns, array $rows, ?array $footer = null): self
    {
        $this->sheets[] = compact('name', 'title', 'columns', 'rows', 'footer');

        return $this;
    }

    public function download(string $filename): BinaryFileResponse
    {
        // Inside storage/, not the system temp dir: under some PHP setups (Windows, artisan
        // serve) that is not writable and tempnam() raises a notice.
        $dir = storage_path('app/private/exports');
        File::ensureDirectoryExists($dir);
        $path = $dir.DIRECTORY_SEPARATOR.Str::uuid()->toString().'.xlsx';

        $this->writeTo($path);

        return response()
            ->download($path, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    public function writeTo(string $path): void
    {
        $scratch = storage_path('app/private/exports');
        File::ensureDirectoryExists($scratch);

        $writer = new Writer(new Options(tempFolder: $scratch));
        $writer->openToFile($path);

        $bold = (new Style)->withFontBold(true);
        $header = (new Style)->withFontBold(true)->withBackgroundColor('E5E7EB');

        foreach ($this->sheets as $i => $sheet) {
            $current = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $current->setName(mb_substr($sheet['name'], 0, 31)); // Excel's limit

            foreach ($sheet['title'] as $line => $text) {
                $writer->addRow(Row::fromValuesWithStyle([$text], $line === 0 ? $bold : new Style));
            }
            if ($sheet['title'] !== []) {
                $writer->addRow(Row::fromValues([]));
            }

            $writer->addRow(Row::fromValuesWithStyle(array_column($sheet['columns'], 'label'), $header));

            $styles = [];
            foreach ($sheet['columns'] as $c => $column) {
                $styles[$c] = isset($column['format']) ? (new Style)->withFormat($column['format']) : new Style;
                $current->setColumnWidth($column['width'] ?? 14, $c + 1);
            }

            foreach ($sheet['rows'] as $row) {
                $writer->addRow(Row::fromValuesWithStyles($row, $styles));
            }

            if ($sheet['footer'] !== null) {
                $footerStyles = array_map(fn (Style $s) => $s->withFontBold(true), $styles);
                $writer->addRow(Row::fromValuesWithStyles($sheet['footer'], $footerStyles));
            }
        }

        $writer->close();
    }
}
