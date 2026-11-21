<?php

namespace App\Actions\Sales;

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\Sale;
use App\Models\User;
use App\Services\Documents\DocumentNumber;
use App\Services\Inventory\StockLedger;
use App\Services\Sales\CartBuilder;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash checkout. In one transaction: number the invoice, write the sale with its price and
 * tax snapshot, take stock out through the ledger (costing each line at the moving-average
 * HPP), and drop the held cart it came from, if any. Any failure, such as a product that
 * sold out a moment ago, rolls back everything.
 */
final class CompleteSale
{
    use ResolvesOpenShift;

    public function __construct(
        private readonly CartBuilder $carts,
        private readonly StockLedger $ledger,
        private readonly DocumentNumber $numbers,
    ) {}

    /**
     * @param  list<array{product_unit_id: int, qty: int, discount_type?: string|null, discount_value?: int|null}>  $items
     */
    public function handle(
        User $cashier,
        array $items,
        int $paidAmount,
        ?DiscountType $discountType = null,
        int $discountValue = 0,
        ?Sale $fromHeld = null,
    ): Sale {
        $shift = $this->openShiftOf($cashier);
        $cart = $this->carts->build($items, $discountType, $discountValue);

        if ($paidAmount < $cart->priced->total) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Uang yang dibayar kurang dari total belanja.',
            ]);
        }

        return DB::transaction(function () use ($cashier, $shift, $cart, $paidAmount, $fromHeld) {
            if ($fromHeld !== null) {
                DiscardHeldSale::deleteLocked($fromHeld, $cashier);
            }

            $sale = Sale::create([
                'number' => $this->numbers->next(DocumentNumber::SALE),
                'shift_id' => $shift->id,
                'user_id' => $cashier->id,
                'status' => SaleStatus::Completed,
                ...$cart->saleAttributes(),
                'payment_method' => PaymentMethod::Cash,
                'paid_amount' => $paidAmount,
                'change_amount' => $paidAmount - $cart->priced->total,
                'completed_at' => now(),
            ]);

            $items = $cart->writeItems($sale);

            // Lock products in a fixed order (by id) so two checkouts sharing products
            // can never deadlock waiting on each other.
            $byProduct = collect($items)->sortBy([['product_id', 'asc'], ['id', 'asc']]);
            $cogsTotal = '0';

            foreach ($byProduct as $item) {
                $movement = $this->ledger->issue(
                    $item->product,
                    $item->baseQty(),
                    StockMovementType::Sale,
                    reference: $sale,
                    user: $cashier,
                );

                $cogs = Decimal::round(Decimal::mul((string) $movement->unit_cost, (string) $item->baseQty()));
                $item->forceFill(['unit_cost' => $movement->unit_cost, 'cogs' => $cogs])->save();
                $cogsTotal = Decimal::add($cogsTotal, $cogs);
            }

            $sale->forceFill(['cogs_total' => Decimal::round($cogsTotal)])->save();

            return $sale->load('items');
        });
    }
}
