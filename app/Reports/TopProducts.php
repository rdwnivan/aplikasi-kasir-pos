<?php

namespace App\Reports;

use App\Enums\SaleStatus;
use App\Support\Apportion;
use App\Support\DateRange;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Best sellers by quantity in base units, net of returns processed in the same period.
 *
 * Money follows the sales report (SalesSummary): revenue and netSales add up to its totals
 * exactly; HPP (4-decimal) is rounded per product, so HPP and profit may differ by a few rupiah.
 *   revenue   = what customers paid for the product (its share of each sale total, after the
 *               transaction discount, incl. PPN) − its refunds
 *   netSales  = the same without PPN (each sale's and refund's PPN shared out the same way)
 *   profit    = netSales − (HPP sold − HPP returned)
 * Each sale total and PPN is split across its lines by line total, each refund's PPN across
 * its lines by amount, all by largest remainder (App\Support\Apportion).
 */
final class TopProducts
{
    /** @return list<array{productId: int, sku: string, name: string, baseUnit: string, soldQty: int, returnedQty: int, netQty: int, revenue: int, netSales: int, cogs: int, profit: int, margin: float}> */
    public static function build(DateRange $range, int $limit = 20): array
    {
        /** @var array<int, array{qty: int, returnedQty: int, revenue: int, tax: int, cogs: string}> $totals */
        $totals = [];
        $add = function (int $productId, string $field, int|string $value) use (&$totals): void {
            $totals[$productId] ??= ['qty' => 0, 'returnedQty' => 0, 'revenue' => 0, 'tax' => 0, 'cogs' => '0'];
            if ($field === 'cogs') {
                $totals[$productId]['cogs'] = Decimal::add($totals[$productId]['cogs'], (string) $value);
            } else {
                $totals[$productId][$field] += (int) $value;
            }
        };

        $saleLines = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', SaleStatus::Completed->value)
            ->where('sales.completed_at', '>=', $range->startsAt())
            ->where('sales.completed_at', '<', $range->endsBefore())
            ->orderBy('sale_items.sale_id')
            ->orderBy('sale_items.id')
            ->get(['sale_items.sale_id', 'sale_items.product_id', 'sale_items.qty', 'sale_items.conversion',
                'sale_items.line_total', 'sale_items.cogs', 'sales.total', 'sales.tax_amount']);

        foreach ($saleLines->groupBy('sale_id') as $lines) {
            $weights = $lines->map(fn ($l) => (int) $l->line_total)->all();
            $paid = Apportion::byWeight((int) $lines->first()->total, $weights);
            $tax = Apportion::byWeight((int) $lines->first()->tax_amount, $weights);
            foreach ($lines as $i => $line) {
                $add((int) $line->product_id, 'qty', (int) $line->qty * (int) $line->conversion);
                $add((int) $line->product_id, 'revenue', $paid[$i]);
                $add((int) $line->product_id, 'tax', $tax[$i]);
                $add((int) $line->product_id, 'cogs', (string) ($line->cogs ?? '0'));
            }
        }

        $returnLines = DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_returns.created_at', '>=', $range->startsAt())
            ->where('sale_returns.created_at', '<', $range->endsBefore())
            ->orderBy('sale_return_items.sale_return_id')
            ->orderBy('sale_return_items.id')
            ->get(['sale_return_items.sale_return_id', 'sale_return_items.product_id', 'sale_return_items.qty',
                'sale_return_items.conversion', 'sale_return_items.amount', 'sale_return_items.cogs', 'sale_returns.tax_amount']);

        foreach ($returnLines->groupBy('sale_return_id') as $lines) {
            $tax = Apportion::byWeight((int) $lines->first()->tax_amount, $lines->map(fn ($l) => (int) $l->amount)->all());
            foreach ($lines as $i => $line) {
                $add((int) $line->product_id, 'returnedQty', (int) $line->qty * (int) $line->conversion);
                $add((int) $line->product_id, 'revenue', -(int) $line->amount);
                $add((int) $line->product_id, 'tax', -$tax[$i]);
                $add((int) $line->product_id, 'cogs', Decimal::mul('-1', (string) $line->cogs));
            }
        }

        $products = DB::table('products')
            ->join('units', 'units.id', '=', 'products.base_unit_id')
            ->whereIn('products.id', array_keys($totals))
            ->get(['products.id', 'products.sku', 'products.name', 'units.name AS unit'])
            ->keyBy('id');

        $rows = [];
        foreach ($totals as $id => $t) {
            $product = $products->get($id);
            if ($product === null) {
                continue;
            }
            $netSales = $t['revenue'] - $t['tax'];
            $cogs = (int) Decimal::round($t['cogs'], 0);
            $profit = $netSales - $cogs;

            $rows[] = [
                'productId' => $id,
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'baseUnit' => (string) $product->unit,
                'soldQty' => $t['qty'],
                'returnedQty' => $t['returnedQty'],
                'netQty' => $t['qty'] - $t['returnedQty'],
                'revenue' => $t['revenue'],
                'netSales' => $netSales,
                'cogs' => $cogs,
                'profit' => $profit,
                'margin' => $netSales === 0 ? 0.0 : round($profit / $netSales * 100, 1),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['netQty'], $b['revenue']] <=> [$a['netQty'], $a['revenue']]);

        return array_slice($rows, 0, $limit);
    }
}
