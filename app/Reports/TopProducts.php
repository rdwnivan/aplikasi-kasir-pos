<?php

namespace App\Reports;

use App\Enums\SaleStatus;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * Best sellers by quantity in base units, net of returns processed in the same period.
 * Revenue is the line totals (after line discounts, before any transaction discount).
 */
final class TopProducts
{
    /** @return list<array{productId: int, sku: string, name: string, baseUnit: string, soldQty: int, returnedQty: int, netQty: int, revenue: int, cogs: int, profit: int}> */
    public static function build(DateRange $range, int $limit = 20): array
    {
        $sold = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->where('sales.completed_at', '>=', $range->startsAt())
            ->where('sales.completed_at', '<', $range->endsBefore())
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id')
            ->selectRaw('SUM(sale_items.qty * sale_items.conversion) AS qty')
            ->selectRaw('SUM(sale_items.line_total) AS revenue')
            ->selectRaw('SUM(sale_items.cogs) AS cogs')
            ->get()
            ->keyBy('product_id');

        $returned = DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_returns.created_at', '>=', $range->startsAt())
            ->where('sale_returns.created_at', '<', $range->endsBefore())
            ->groupBy('sale_return_items.product_id')
            ->selectRaw('sale_return_items.product_id')
            ->selectRaw('SUM(sale_return_items.qty * sale_return_items.conversion) AS qty')
            ->selectRaw('SUM(sale_return_items.cogs) AS cogs')
            ->get()
            ->keyBy('product_id');

        $ids = $sold->keys()->merge($returned->keys())->unique()->all();
        $products = DB::table('products')
            ->join('units', 'units.id', '=', 'products.base_unit_id')
            ->whereIn('products.id', $ids)
            ->get(['products.id', 'products.sku', 'products.name', 'units.name AS unit'])
            ->keyBy('id');

        $rows = [];
        foreach ($ids as $id) {
            $product = $products->get($id);
            if ($product === null) {
                continue;
            }
            $s = $sold->get($id);
            $r = $returned->get($id);
            $soldQty = (int) ($s->qty ?? 0);
            $returnedQty = (int) ($r->qty ?? 0);
            $revenue = Sql::rupiah($s->revenue ?? 0);
            $cogs = Sql::rupiah($s->cogs ?? 0) - Sql::rupiah($r->cogs ?? 0);

            $rows[] = [
                'productId' => (int) $id,
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'baseUnit' => (string) $product->unit,
                'soldQty' => $soldQty,
                'returnedQty' => $returnedQty,
                'netQty' => $soldQty - $returnedQty,
                'revenue' => $revenue,
                'cogs' => $cogs,
                'profit' => $revenue - $cogs,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['netQty'], $b['revenue']] <=> [$a['netQty'], $a['revenue']]);

        return array_slice($rows, 0, $limit);
    }
}
