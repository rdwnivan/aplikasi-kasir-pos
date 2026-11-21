<?php

namespace App\Actions\Sales;

use App\Enums\DiscountType;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\CartBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Parks a cart (e.g. the customer went to grab one more item) so the cashier can serve the
 * next person. Held carts reserve no stock; stock moves only on completion.
 */
final class HoldSale
{
    use ResolvesOpenShift;

    public function __construct(private readonly CartBuilder $carts) {}

    /**
     * @param  list<array{product_unit_id: int, qty: int, discount_type?: string|null, discount_value?: int|null}>  $items
     */
    public function handle(
        User $cashier,
        array $items,
        ?string $label = null,
        ?DiscountType $discountType = null,
        int $discountValue = 0,
        ?Sale $replacing = null,
    ): Sale {
        $shift = $this->openShiftOf($cashier);
        $cart = $this->carts->build($items, $discountType, $discountValue);

        return DB::transaction(function () use ($cashier, $shift, $cart, $label, $replacing) {
            if ($replacing !== null) {
                DiscardHeldSale::deleteLocked($replacing, $cashier);
            }

            $sale = Sale::create([
                'shift_id' => $shift->id,
                'user_id' => $cashier->id,
                'status' => SaleStatus::Held,
                'held_label' => $label,
                ...$cart->saleAttributes(),
            ]);

            $cart->writeItems($sale);

            return $sale->load('items');
        });
    }
}
