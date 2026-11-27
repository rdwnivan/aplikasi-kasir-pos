<?php

namespace App\Actions\Sales;

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\Documents\DocumentNumber;
use App\Services\Inventory\StockLedger;
use App\Services\Receivables\CustomerLedger;
use App\Services\Sales\CartBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash or kasbon checkout. In one transaction: number the invoice, write the sale with its price and
 * tax snapshot, take stock out through the ledger (costing each line at the moving-average
 * HPP), and drop the held cart it came from, if any. Any failure, such as a product that
 * sold out a moment ago, rolls back everything.
 */
final class CompleteSale
{
    use BooksSaleStock, ResolvesOpenShift;

    public function __construct(
        private readonly CartBuilder $carts,
        private readonly StockLedger $ledger,
        private readonly DocumentNumber $numbers,
        private readonly CustomerLedger $customers,
    ) {}

    /**
     * With a $customer this is kasbon (DESIGN.md §17): $paidAmount is then the cash down
     * payment, taken exactly (0 up to less than the total), and the rest is owed.
     *
     * @param  list<array{product_unit_id: int, qty: int, discount_type?: string|null, discount_value?: int|null}>  $items
     */
    public function handle(
        User $cashier,
        array $items,
        int $paidAmount,
        ?DiscountType $discountType = null,
        int $discountValue = 0,
        ?Sale $fromHeld = null,
        ?Customer $customer = null,
    ): Sale {
        $shift = $this->openShiftOf($cashier);
        $cart = $this->carts->build($items, $discountType, $discountValue);
        $total = $cart->priced->total;

        if ($customer === null && $paidAmount < $total) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Uang yang dibayar kurang dari total belanja.',
            ]);
        }
        if ($customer !== null && ($paidAmount < 0 || $paidAmount >= $total)) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Uang muka harus kurang dari total belanja.',
            ]);
        }

        return DB::transaction(function () use ($cashier, $shift, $cart, $total, $paidAmount, $fromHeld, $customer) {
            $this->lockOpenShift($shift);

            // Lock order: shift → customer → sale → products.
            $credit = $total - $paidAmount;
            $lockedCustomer = null;
            if ($customer !== null) {
                $lockedCustomer = $this->customers->lock($customer);
                CustomerLedger::ensureCanBorrow($lockedCustomer, $credit);
            }

            if ($fromHeld !== null) {
                DiscardHeldSale::deleteLocked($fromHeld, $cashier);
            }

            $sale = Sale::create([
                'number' => $this->numbers->next(DocumentNumber::SALE),
                'shift_id' => $shift->id,
                'user_id' => $cashier->id,
                'customer_id' => $lockedCustomer?->id,
                'status' => SaleStatus::Completed,
                ...$cart->saleAttributes(),
                'payment_method' => $lockedCustomer === null ? PaymentMethod::Cash : PaymentMethod::Credit,
                'credit_amount' => $lockedCustomer === null ? null : $credit,
                'paid_amount' => $paidAmount,
                'change_amount' => $lockedCustomer === null ? $paidAmount - $total : 0,
                'completed_at' => now(),
            ]);

            if ($lockedCustomer !== null) {
                $this->customers->charge($lockedCustomer, $sale, $credit, $cashier);
            }

            $cart->writeItems($sale);
            $this->bookSaleStock($sale, $cashier);

            return $sale->load('items');
        });
    }
}
