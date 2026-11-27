<?php

namespace App\Exports;

/**
 * One report as a format-neutral document: a heading and one or more tables. Built by
 * ReportTables and written out by Spreadsheet (.xlsx) or ReportPdf (.pdf), so both formats
 * always carry the same columns, rows and totals.
 *
 * @phpstan-type Column array{label: string, format?: string, kind?: 'date'|'month'|'datetime', width?: float}
 * @phpstan-type Sheet array{name: string, columns: list<Column>, rows: list<list<mixed>>, footer: list<mixed>|null}
 */
final class ReportDocument
{
    /** @var list<Sheet> */
    private array $sheets = [];

    /**
     * @param  string  $period  "Periode … (WIB)" or "Per … WIB"
     * @param  string  $note  how the figures are computed
     * @param  string  $filename  without extension
     * @param  bool  $landscape  for wide tables in the PDF
     */
    public function __construct(
        public readonly string $name,
        public readonly string $period,
        public readonly string $note,
        public readonly string $filename,
        public readonly bool $landscape = false,
    ) {}

    /**
     * @param  list<Column>  $columns
     * @param  list<list<mixed>>  $rows
     * @param  list<mixed>|null  $footer  a totals row
     */
    public function sheet(string $name, array $columns, array $rows, ?array $footer = null): self
    {
        $this->sheets[] = compact('name', 'columns', 'rows', 'footer');

        return $this;
    }

    /** @return list<Sheet> */
    public function sheets(): array
    {
        return $this->sheets;
    }

    /**
     * The lines above each table in the workbook.
     *
     * @return list<string>
     */
    public function title(): array
    {
        return [$this->name.' — '.config('pos.store.name'), $this->period, $this->note];
    }

    public function toSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        foreach ($this->sheets as $sheet) {
            $spreadsheet->sheet($sheet['name'], $this->title(), $sheet['columns'], $sheet['rows'], $sheet['footer']);
        }

        return $spreadsheet;
    }
}
