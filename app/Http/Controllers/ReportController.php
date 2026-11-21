<?php

namespace App\Http\Controllers;

use App\Reports\InventoryValuation;
use App\Reports\SalesSummary;
use App\Reports\ShiftRecap;
use App\Reports\TopProducts;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Owner reports. Date ranges are calendar days in Asia/Jakarta. */
class ReportController extends Controller
{
    public function sales(Request $request): Response
    {
        $group = $request->query('group') === 'month' ? 'month' : 'day';
        $range = DateRange::fromRequest($request, defaultDays: $group === 'month' ? 365 : 30);

        return Inertia::render('reports/sales', [
            'filters' => [...$range->toArray(), 'group' => $group],
            'report' => SalesSummary::build($range, $group),
        ]);
    }

    public function products(Request $request): Response
    {
        $range = DateRange::fromRequest($request, defaultDays: 30);

        return Inertia::render('reports/products', [
            'filters' => $range->toArray(),
            'rows' => TopProducts::build($range, 50),
        ]);
    }

    public function inventory(): Response
    {
        return Inertia::render('reports/inventory', [
            'report' => InventoryValuation::build(),
            'asOf' => now()->toIso8601String(),
        ]);
    }

    public function shifts(Request $request): Response
    {
        $range = DateRange::fromRequest($request, defaultDays: 7);

        return Inertia::render('reports/shifts', [
            'filters' => $range->toArray(),
            'report' => ShiftRecap::build($range),
        ]);
    }
}
