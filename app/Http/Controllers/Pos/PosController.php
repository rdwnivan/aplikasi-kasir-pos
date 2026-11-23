<?php

namespace App\Http\Controllers\Pos;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Services\Payments\MidtransClient;
use App\Services\Sales\TaxSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(Request $request, MidtransClient $midtrans): Response
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
            // Lazy, so a partial reload that leaves it out doesn't use up the one-time display.
            'lastSale' => fn () => self::lastSale($request),
            'qrisEnabled' => $midtrans->isConfigured(),
            'pendingQris' => self::pendingQris($shift),
        ]);
    }

    /** @return array<string, mixed> */
    public static function shiftSummary(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'openedAt' => $shift->opened_at->toIso8601String(),
            'openingCash' => $shift->opening_cash,
            'salesCount' => $shift->cashSalesCount(),
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

    /**
     * The sale to show in the "Transaksi berhasil" dialog, once: the id is taken out of the
     * session here. QRIS stores it with put() from a polling XHR, so it would otherwise stick.
     *
     * @return array<string, mixed>|null
     */
    private static function lastSale(Request $request): ?array
    {
        $id = $request->session()->pull('last_sale_id');
        $sale = is_numeric($id) ? Sale::query()->where('user_id', $request->user()->id)->find((int) $id) : null;

        return $sale === null || $sale->status !== SaleStatus::Completed ? null : [
            'id' => $sale->id,
            'number' => $sale->number,
            'total' => $sale->total,
            'paid' => $sale->paid_amount,
            'change' => $sale->change_amount,
            'method' => $sale->payment_method?->value,
            'cashAmount' => $sale->cashPart(),
            'qrisAmount' => $sale->qrisPart(),
        ];
    }

    /** @return array<string, mixed>|null The QRIS this till is waiting on, if any. */
    private static function pendingQris(Shift $shift): ?array
    {
        $sale = $shift->sales()->where('status', SaleStatus::PendingPayment)->latest('id')->first();

        return $sale === null ? null : [
            'id' => $sale->id,
            'number' => $sale->number,
            'total' => $sale->total,
            'amount' => $sale->qrisPart(),
            'cashAmount' => $sale->cashPart(),
            'qrUrl' => $sale->qr_url,
            'qrString' => $sale->qr_string,
            'expiresAt' => $sale->payment_expires_at?->toIso8601String(),
        ];
    }
}
