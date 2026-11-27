<?php

namespace App\Http\Controllers\Pos;

use App\Enums\LedgerEntryType;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPayment;
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
            return Inertia::render('pos/open-shift', [
                'closedByOther' => self::closedByOther($request),
            ]);
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
            'customers' => self::customers(),
        ]);
    }

    /**
     * Active customers for the kasbon picker, preloaded like the catalogue (a store has tens
     * to a few hundred regulars). DESIGN.md §17.
     *
     * @return list<array{id: int, name: string, phone: string|null, balance: int, creditLimit: int, available: int}>
     */
    public static function customers(): array
    {
        return array_values(Customer::query()->active()->orderBy('name')->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'balance' => $c->balance,
                'creditLimit' => $c->credit_limit,
                'available' => $c->availableCredit(),
            ])
            ->all());
    }

    /**
     * When the user's last shift was closed by an owner (DESIGN.md §13), say so on the
     * "Buka shift" screen until they open a new one.
     *
     * @return array{by: string, at: string, reason: string|null}|null
     */
    private static function closedByOther(Request $request): ?array
    {
        $last = Shift::query()->with('closer')->where('user_id', $request->user()->id)->latest('opened_at')->latest('id')->first();

        if ($last === null || ! $last->closedByOther() || $last->closed_at === null) {
            return null;
        }

        return [
            'by' => (string) $last->closer?->name,
            'at' => $last->closed_at->toIso8601String(),
            'reason' => $last->close_reason,
        ];
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
            'creditSalesTotal' => $shift->creditSalesTotal(),
            // Cash pelunasan (in the drawer) and QRIS pelunasan (not), DESIGN.md §18.
            'paymentsCount' => $shift->receivedPayments(CustomerPayment::CASH)->count(),
            'paymentsTotal' => $shift->cashPaymentsTotal(),
            'qrisPaymentsCount' => $shift->receivedPayments(CustomerPayment::QRIS)->count(),
            'qrisPaymentsTotal' => $shift->qrisPaymentsTotal(),
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
            'creditAmount' => $sale->creditPart(),
            'customer' => self::creditCustomer($sale),
        ];
    }

    /**
     * Who took the kasbon and what they owed right after this sale (the ledger row's
     * balance_after, so a reprint later shows the same figure).
     *
     * @return array{name: string, balance: int}|null
     */
    public static function creditCustomer(Sale $sale): ?array
    {
        if ($sale->customer_id === null) {
            return null;
        }

        $entry = $sale->ledgerEntries()->where('type', LedgerEntryType::Sale)->with('customer')->first();

        return $entry === null ? null : ['name' => $entry->customer->name, 'balance' => $entry->balance_after];
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
