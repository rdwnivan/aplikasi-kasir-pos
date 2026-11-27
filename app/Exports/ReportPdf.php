<?php

namespace App\Exports;

use App\Models\User;
use App\Support\DateRange;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes a ReportDocument as an A4 PDF with dompdf, from the Blade view reports/pdf. Numbers
 * become Indonesian text (Rp 15.000, 12,5%, 21 Nov 2026 10.00) since a PDF is for reading.
 *
 * @phpstan-import-type Column from ReportDocument
 */
final class ReportPdf
{
    /** Rows per <table> in the view; see reports/pdf.blade.php. */
    public const ROWS_PER_TABLE = 50;

    public function html(ReportDocument $document, User $by): string
    {
        return view('reports.pdf', [
            'document' => $document,
            'store' => config('pos.store'),
            'printedAt' => CarbonImmutable::now(DateRange::TIMEZONE)->settings(['locale' => 'id'])->translatedFormat('j M Y H.i'),
            'printedBy' => $by->name,
        ])->render();
    }

    /** @throws TooManyRowsForPdf */
    public function download(ReportDocument $document, User $by): Response
    {
        // dompdf's cost grows with cells, so wide tables get fewer rows (docs/DESIGN.md §16).
        $maxCells = (int) config('pos.exports.pdf_max_cells', 6500);
        foreach ($document->sheets() as $sheet) {
            $limit = intdiv($maxCells, max(1, count($sheet['columns'])));
            if (count($sheet['rows']) > $limit) {
                throw new TooManyRowsForPdf(count($sheet['rows']), $limit);
            }
        }

        $dompdf = $this->dompdf();
        $dompdf->setPaper('A4', $document->landscape ? 'landscape' : 'portrait');
        $dompdf->loadHtml($this->html($document, $by));
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            28,
            $canvas->get_height() - 24,
            $document->name.' — '.config('pos.store.name').'   ·   Halaman {PAGE_NUM} dari {PAGE_COUNT}',
            $font,
            7,
            [0.4, 0.4, 0.4],
        );

        return response((string) $dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->filename.'.pdf"',
        ]);
    }

    /** A dompdf that only reads the HTML it is given: no remote files, no PHP, no JavaScript. */
    public function dompdf(): Dompdf
    {
        // Inside storage/, not the system temp dir (see Spreadsheet::download()).
        $scratch = storage_path('app/private/exports');
        File::ensureDirectoryExists($scratch);

        return new Dompdf((new Options)
            ->setIsRemoteEnabled(false)
            ->setIsPhpEnabled(false)
            ->setIsJavascriptEnabled(false)
            ->setIsHtml5ParserEnabled(true)
            ->setTempDir($scratch)
            ->setDefaultFont('DejaVu Sans'));
    }

    /**
     * One cell as text.
     *
     * @param  Column  $column
     */
    public static function cell(mixed $value, array $column): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $text = is_scalar($value) ? (string) $value : '';

        switch ($column['kind'] ?? null) {
            case 'date':
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? self::date($text, 'j M Y') : $text;
            case 'month':
                return preg_match('/^\d{4}-\d{2}$/', $text) === 1 ? self::date($text.'-01', 'M Y') : $text;
            case 'datetime':
                return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $text) === 1 ? self::date($text, 'j M Y H.i') : $text;
        }

        if (! is_numeric($value)) {
            return $text;
        }

        return match ($column['format'] ?? null) {
            Spreadsheet::RUPIAH => self::rupiah(Decimal::round($text, 0)),
            Spreadsheet::RUPIAH_CENTS => self::rupiah(Decimal::round($text, 2)),
            Spreadsheet::NUMBER => self::thousands(Decimal::round($text, 0)),
            Spreadsheet::PERCENT => self::thousands(Decimal::round($text, 1)).'%',
            default => $text,
        };
    }

    private static function date(string $value, string $format): string
    {
        return CarbonImmutable::parse($value, DateRange::TIMEZONE)->settings(['locale' => 'id'])->translatedFormat($format);
    }

    /** "-2500" → "-Rp 2.500"; "8123.46" → "Rp 8.123,46". */
    private static function rupiah(string $amount): string
    {
        $negative = str_starts_with($amount, '-');

        return ($negative ? '-' : '').'Rp '.self::thousands(ltrim($amount, '-'));
    }

    /** Group a plain decimal string with dots and use a decimal comma: "-1234.5" → "-1.234,5". */
    private static function thousands(string $number): string
    {
        $negative = str_starts_with($number, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($number, '-'), 2), 2, null);
        $grouped = strrev(implode('.', str_split(strrev($whole), 3)));
        $result = $fraction === null ? $grouped : $grouped.','.$fraction;

        return ($negative && trim($result, '0.,') !== '' ? '-' : '').$result;
    }
}
