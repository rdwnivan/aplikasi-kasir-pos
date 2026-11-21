<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Services\Sales\TaxSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(Request $request): Response
    {
        $shift = Shift::query()->open()->where('user_id', $request->user()->id)->first();

        if ($shift === null) {
            return Inertia::render('pos/open-shift');
        }

        $tax = TaxSettings::fromConfig();

        return Inertia::render('pos/index', [
            'shift' => self::shiftSummary($shift),
            'catalog' => self::catalog(),
            'heldSales' => self::heldSales($shift),
            'tax' => [
                'enabled' => $tax->enabled,
                'rate' => (float) $tax->rate,
                'dppNumerator' => $tax->dppNumerator,
                'dppDenominator' => $tax->dppDenominator,
                'pricesIncludeTax' => $tax->pricesIncludeTax,
            ],
            'lastSale' => self::lastSale($request),
        ]);
    }

    /** @return array<string, mixed> */
    public static function shiftSummary(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'openedAt' => $shift->opened_at->toIso8601String(),
            'openingCash' => $shift->opening_cash,
            'salesCount' => $shift->sales()
                ->where('status', SaleStatus::Completed)
                ->where('payment_method', PaymentMethod::Cash)
                ->count(),
            'cashSalesTotal' => $shift->cashSalesTotal(),
            'refundsCount' => $shift->returns()->count(),
            'refundsTotal' => $shift->refundsTotal(),
            'expectedCash' => $shift->expectedCash(),
        ];
    }

    /**
     * Every sellable unit, preloaded so search and barcode scans are instant on the client.
     * Fine for a single store (hundreds to a few thousand SKUs); a server-side search
     * endpoint is the upgrade path for bigger catalogues.
     *
     * @return list<array<string, mixed>>
     */
    private static function catalog(): array
    {
        return array_values(ProductUnit::query()
            ->with(['product.category', 'unit'])
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->get()
            ->sort(fn (ProductUnit $a, ProductUnit $b) => [$a->product->name, $a->conversion] <=> [$b->product->name, $b->conversion])
            ->map(fn (ProductUnit $u) => [
                'id' => $u->id,
                'productId' => $u->product_id,
                'sku' => $u->product->sku,
                'name' => $u->product->name,
                'category' => $u->product->category?->name,
                'unit' => $u->unit->name,
                'conversion' => $u->conversion,
                'price' => $u->price,
                'barcode' => $u->barcode,
                'stock' => $u->product->stock_on_hand,
            ])
            ->all());
    }

    /** @return list<array<string, mixed>> */
    private static function heldSales(Shift $shift): array
    {
        return array_values($shift->sales()
            ->where('status', SaleStatus::Held)
            ->with('items')
            ->latest()
            ->get()
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'label' => $sale->held_label,
                'total' => $sale->total,
                'heldAt' => $sale->created_at?->toIso8601String(),
                'discountType' => $sale->discount_type?->value,
                'discountValue' => $sale->discount_value ?? 0,
                'items' => $sale->items->map(fn (SaleItem $item) => [
                    'productUnitId' => $item->product_unit_id,
                    'qty' => $item->qty,
                    'discountType' => $item->discount_type?->value,
                    'discountValue' => $item->discount_value ?? 0,
                ])->all(),
            ])
            ->all());
    }

    /** @return array<string, mixed>|null */
    private static function lastSale(Request $request): ?array
    {
        $id = $request->session()->get('last_sale_id');
        $sale = is_numeric($id) ? Sale::query()->where('user_id', $request->user()->id)->find((int) $id) : null;

        return $sale === null ? null : [
            'id' => $sale->id,
            'number' => $sale->number,
            'total' => $sale->total,
            'paid' => $sale->paid_amount,
            'change' => $sale->change_amount,
        ];
    }
}
