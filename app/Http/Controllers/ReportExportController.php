<?php

namespace App\Http\Controllers;

use App\Exports\ReportDocument;
use App\Exports\ReportPdf;
use App\Exports\ReportTables;
use App\Exports\TooManyRowsForPdf;
use App\Support\DateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same reports as the /laporan pages, as .xlsx or .pdf downloads with the same filters.
 * The tables themselves are defined once, in ReportTables.
 */
class ReportExportController extends Controller
{
    public function __construct(private readonly ReportPdf $pdf) {}

    public function sales(Request $request, string $format): Response
    {
        $group = $request->query('group') === 'month' ? 'month' : 'day';
        $range = DateRange::fromRequest($request, defaultDays: $group === 'month' ? 365 : 30);

        return $this->download(ReportTables::sales($range, $group), $format, $request);
    }

    public function products(Request $request, string $format): Response
    {
        return $this->download(ReportTables::products(DateRange::fromRequest($request, defaultDays: 30)), $format, $request);
    }

    public function inventory(Request $request, string $format): Response
    {
        return $this->download(ReportTables::inventory(), $format, $request);
    }

    public function shifts(Request $request, string $format): Response
    {
        return $this->download(ReportTables::shifts(DateRange::fromRequest($request, defaultDays: 7)), $format, $request);
    }

    public function receivables(Request $request, string $format): Response
    {
        return $this->download(ReportTables::receivables(), $format, $request);
    }

    private function download(ReportDocument $document, string $format, Request $request): Response
    {
        if ($format === 'xlsx') {
            return $document->toSpreadsheet()->download($document->filename.'.xlsx');
        }

        try {
            return $this->pdf->download($document, $request->user());
        } catch (TooManyRowsForPdf $e) {
            return $this->backWithError($e->getMessage());
        }
    }

    private function backWithError(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
