<?php

namespace App\Actions\Sales;

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\User;
use App\Services\Documents\DocumentNumber;
use App\Services\Payments\MidtransClient;
use App\Services\Payments\MidtransException;
use App\Services\Sales\CartBuilder;
use App\Services\Sales\CartLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * QRIS checkout (whole, or the rest after a cash part: DESIGN.md §11), step one: write the sale as pending_payment (numbered, priced, items and
 * tax snapshot, but no stock movement) and ask Midtrans for a QR code. Stock moves only when
 * the payment settles (QrisPayments::settle).
 *
 * The Midtrans call happens after the sale is committed, never inside a DB transaction; if
 * it fails the sale is marked cancelled and the cashier sees why.
 */
final class StartQrisPayment
{
    use ResolvesOpenShift;

    public function __construct(
        private readonly CartBuilder $carts,
        private readonly DocumentNumber $numbers,
        private readonly MidtransClient $midtrans,
    ) {}

    /**
     * @param  list<array{product_unit_id: int, qty: int, discount_type?: string|null, discount_value?: int|null}>  $items
     */
    public function handle(
        User $cashier,
        array $items,
        ?DiscountType $discountType = null,
        int $discountValue = 0,
        ?Sale $fromHeld = null,
        ?int $cashAmount = null,
    ): Sale {
        if (! $this->midtrans->isConfigured()) {
            throw ValidationException::withMessages(['payment' => 'QRIS belum aktif: isi MIDTRANS_SERVER_KEY di file .env.']);
        }

        $shift = $this->openShiftOf($cashier);
        $cart = $this->carts->build($items, $discountType, $discountValue);

        if ($cart->priced->total < 1) {
            throw ValidationException::withMessages(['payment' => 'Total nol tidak bisa dibayar dengan QRIS.']);
        }

        // Mixed payment: the cashier has already taken this much in cash; QRIS pays the rest.
        if ($cashAmount !== null && ($cashAmount < 1 || $cashAmount >= $cart->priced->total)) {
            throw ValidationException::withMessages([
                'cash_amount' => 'Bagian tunai harus lebih dari Rp 0 dan kurang dari total belanja.',
            ]);
        }

        $this->ensureStock($cart->units, $cart->lines);

        if (Sale::query()->where('shift_id', $shift->id)->where('status', SaleStatus::PendingPayment)->exists()) {
            throw ValidationException::withMessages(['payment' => 'Masih ada QRIS yang menunggu pembayaran. Selesaikan atau batalkan dulu.']);
        }

        $sale = DB::transaction(function () use ($cashier, $shift, $cart, $fromHeld, $cashAmount) {
            if ($fromHeld !== null) {
                DiscardHeldSale::deleteLocked($fromHeld, $cashier);
            }

            $sale = Sale::create([
                'number' => $this->numbers->next(DocumentNumber::SALE),
                'shift_id' => $shift->id,
                'user_id' => $cashier->id,
                'status' => SaleStatus::PendingPayment,
                ...$cart->saleAttributes(),
                'payment_method' => $cashAmount === null ? PaymentMethod::Qris : PaymentMethod::Split,
                'split_cash_amount' => $cashAmount,
            ]);
            $cart->writeItems($sale);

            return $sale;
        });

        try {
            $charge = $this->midtrans->chargeQris((string) $sale->number, $sale->qrisPart());
        } catch (MidtransException $e) {
            $sale->forceFill(['status' => SaleStatus::Cancelled])->save();

            throw ValidationException::withMessages(['payment' => $e->getMessage()]);
        }

        $expiresAt = $charge['expires_at'] !== null
            ? CarbonImmutable::parse($charge['expires_at'], 'Asia/Jakarta') // Midtrans reports WIB
            : now()->addMinutes((int) config('services.midtrans.qris_expiry_minutes', 15));

        $sale->forceFill([
            'payment_reference' => $charge['transaction_id'],
            'qr_string' => $charge['qr_string'],
            'qr_url' => $charge['qr_url'],
            'payment_expires_at' => $expiresAt,
        ])->save();

        return $sale;
    }

    /**
     * A friendly early check so the customer is not asked to pay for goods that are already
     * gone. Not a reservation: settlement re-checks under the ledger's row locks.
     *
     * @param  list<ProductUnit>  $units
     * @param  list<CartLine>  $lines
     */
    private function ensureStock(array $units, array $lines): void
    {
        $needed = [];
        $products = [];
        foreach ($units as $i => $unit) {
            $needed[$unit->product_id] = ($needed[$unit->product_id] ?? 0) + $lines[$i]->qty * $unit->conversion;
            $products[$unit->product_id] = $unit->product;
        }

        foreach ($needed as $productId => $qty) {
            $product = $products[$productId];
            if ($product->stock_on_hand < $qty) {
                throw ValidationException::withMessages([
                    'items' => "Stok {$product->name} tidak cukup (tersisa {$product->stock_on_hand}).",
                ]);
            }
        }
    }
}
