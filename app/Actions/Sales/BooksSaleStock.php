<?php

namespace App\Actions\Sales;

use App\Enums\StockMovementType;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Support\Decimal;

/**
 * Takes a sale's goods out of stock and records the HPP of each line. Call inside the
 * transaction that completes the sale. Needs a StockLedger in $this->ledger.
 *
 * @property-read StockLedger $ledger
 */
trait BooksSaleStock
{
    private function bookSaleStock(Sale $sale, User $user): void
    {
        // Lock products in a fixed order (by id) so two checkouts sharing products can
        // never deadlock waiting on each other.
        $items = $sale->items()->with(['product' => fn ($q) => $q->withTrashed()])->orderBy('product_id')->orderBy('id')->get();
        $cogsTotal = '0';

        foreach ($items as $item) {
            /** @var SaleItem $item */
            $movement = $this->ledger->issue(
                $item->product,
                $item->baseQty(),
                StockMovementType::Sale,
                reference: $sale,
                user: $user,
            );

            $cogs = Decimal::round(Decimal::mul((string) $movement->unit_cost, (string) $item->baseQty()));
            $item->forceFill(['unit_cost' => $movement->unit_cost, 'cogs' => $cogs])->save();
            $cogsTotal = Decimal::add($cogsTotal, $cogs);
        }

        $sale->forceFill(['cogs_total' => Decimal::round($cogsTotal)])->save();
    }
}
