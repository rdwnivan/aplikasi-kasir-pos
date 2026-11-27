<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Queries\LowStock;
use App\Queries\OpenPurchaseOrders;
use App\Reports\InventoryValuation;
use App\Reports\Receivables;
use App\Reports\SalesSummary;
use App\Reports\TopProducts;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owners get the business at a glance (30-day sales and profit, best sellers, low stock);
 * warehouse staff get what needs restocking; cashiers go straight to the till.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $isOwner = $user->hasRole(Role::Owner->value);
        $canManageStock = $isOwner || $user->hasRole(Role::Warehouse->value);

        if (! $canManageStock && $user->hasRole(Role::Cashier->value)) {
            return to_route('pos.index');
        }

        return Inertia::render('dashboard', [
            'lowStock' => $canManageStock ? [
                'count' => LowStock::count(),
                'items' => LowStock::list(8),
            ] : null,
            'openOrders' => $canManageStock ? OpenPurchaseOrders::summary() : null,
            'overview' => $isOwner ? self::overview() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private static function overview(): array
    {
        $today = CarbonImmutable::now(DateRange::TIMEZONE)->toDateString();
        $range = DateRange::of(CarbonImmutable::parse($today)->subDays(29)->toDateString(), $today);
        $summary = SalesSummary::build($range);
        $todayRow = end($summary['rows']) ?: ['gross' => 0, 'transactions' => 0, 'grossProfit' => 0];

        return [
            'range' => $range->toArray(),
            'today' => [
                'gross' => $todayRow['gross'],
                'transactions' => $todayRow['transactions'],
                'grossProfit' => $todayRow['grossProfit'],
            ],
            'totals' => $summary['totals'],
            'daily' => array_map(fn (array $row) => [
                'date' => $row['period'],
                'gross' => $row['gross'],
                'grossProfit' => $row['grossProfit'],
                'transactions' => $row['transactions'],
            ], $summary['rows']),
            'topProducts' => TopProducts::build($range, 5),
            'inventoryValue' => InventoryValuation::build()['total'],
            'receivables' => Arr::only(Receivables::build()['totals'], ['customers', 'balance', 'overdue']),
        ];
    }
}
