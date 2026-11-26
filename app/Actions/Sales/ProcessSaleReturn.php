<?php

namespace App\Actions\Sales;

use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\Auth\OwnerPin;
use App\Services\Documents\DocumentNumber;
use App\Services\Inventory\StockLedger;
use App\Support\Apportion;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returns goods from a completed sale, approved by an owner's PIN.
 *
 * - Each line may be returned up to what was sold minus what earlier returns took back.
 * - Goods re-enter stock through the ledger as `sale_return` at the HPP the original line
 *   left with, so the moving average is restored rather than distorted.
 * - The refund is what the customer actually paid for those units: the sale total (after
 *   transaction discount and PPN) is split across lines by line total, and each line's share
 *   is refunded pro rata. Returning a line's last unit refunds exactly what is left of its
 *   share, so returning everything always refunds the sale total to the rupiah.
 * - Cash comes out of the processing user's open shift, which must exist.
 */
final class ProcessSaleReturn
{
    use ResolvesOpenShift;

    public function __construct(
        private readonly OwnerPin $ownerPin,
        private readonly StockLedger $ledger,
        private readonly DocumentNumber $numbers,
    ) {}

    /**
     * @param  array<int, int>  $quantities  sale_item_id => qty to return (in the unit sold)
     */
    public function handle(User $actor, Sale $sale, array $quantities, string $ownerPin, ?string $reason = null): SaleReturn
    {
        $quantities = array_filter($quantities, fn (int $qty) => $qty !== 0);

        if ($quantities === []) {
            throw ValidationException::withMessages(['items' => 'Pilih minimal satu barang yang diretur.']);
        }
        foreach ($quantities as $itemId => $qty) {
            if ($qty < 0) {
                throw ValidationException::withMessages(["items.$itemId" => 'Jumlah retur tidak boleh negatif.']);
            }
        }

        $shift = $this->openShiftOf($actor);
        $approver = $this->ownerPin->approve($actor, $ownerPin);

        return DB::transaction(function () use ($actor, $sale, $quantities, $reason, $shift, $approver) {
            $this->lockOpenShift($shift);

            // One return at a time per sale, so two terminals cannot both refund the last unit.
            /** @var Sale $locked */
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SaleStatus::Completed) {
                throw ValidationException::withMessages(['sale' => 'Hanya transaksi yang sudah selesai yang bisa diretur.']);
            }

            $items = $locked->items()->withSum('returnItems as returned_qty', 'qty')->withSum('returnItems as returned_amount', 'amount')->get()->keyBy('id');
            $shares = self::lineShares($locked, $items->all());

            $errors = [];
            foreach ($quantities as $itemId => $qty) {
                $item = $items->get($itemId);
                if ($item === null) {
                    $errors["items.$itemId"] = 'Barang ini bukan bagian dari transaksi.';

                    continue;
                }
                $remaining = $item->qty - (int) $item->getAttribute('returned_qty');
                if ($qty > $remaining) {
                    $errors["items.$itemId"] = $remaining === 0
                        ? "{$item->product_name} sudah diretur semua."
                        : "Maksimal {$remaining} {$item->unit_name} {$item->product_name} yang bisa diretur.";
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $return = SaleReturn::create([
                'number' => $this->numbers->next(DocumentNumber::SALE_RETURN),
                'sale_id' => $locked->id,
                'shift_id' => $shift->id,
                'user_id' => $actor->id,
                'approved_by' => $approver->id,
                'total' => 0,
                'reason' => $reason,
            ]);

            // Same lock order as checkout and goods receipts: by product id.
            $lines = [];
            foreach ($quantities as $itemId => $qty) {
                $item = $items->get($itemId);
                if ($item instanceof SaleItem) { // always, after the checks above
                    $lines[] = ['item' => $item, 'qty' => $qty];
                }
            }
            usort($lines, fn (array $a, array $b) => [$a['item']->product_id, $a['item']->id] <=> [$b['item']->product_id, $b['item']->id]);

            $total = 0;
            $cogsTotal = '0';

            foreach ($lines as ['item' => $item, 'qty' => $qty]) {
                $returnedQty = (int) $item->getAttribute('returned_qty');
                $returnedAmount = (int) $item->getAttribute('returned_amount');
                $share = $shares[$item->id];

                $amount = $returnedQty + $qty === $item->qty
                    ? $share - $returnedAmount
                    : intdiv(2 * $share * $qty + $item->qty, 2 * $item->qty); // round half up

                $unitCost = (string) ($item->unit_cost ?? '0');
                $baseQty = $qty * $item->conversion;
                $cogs = Decimal::round(Decimal::mul($unitCost, (string) $baseQty));

                $return->items()->create([
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'qty' => $qty,
                    'conversion' => $item->conversion,
                    'amount' => $amount,
                    'unit_cost' => $unitCost,
                    'cogs' => $cogs,
                ]);

                $this->ledger->receive(
                    $item->product()->withTrashed()->firstOrFail(),
                    $baseQty,
                    $unitCost,
                    StockMovementType::SaleReturn,
                    reference: $return,
                    user: $actor,
                    note: $locked->number,
                );

                $total += $amount;
                $cogsTotal = Decimal::add($cogsTotal, $cogs);
            }

            $return->forceFill([
                'total' => $total,
                'tax_amount' => $this->taxPart($locked, $total, $return->id),
                'cogs_total' => Decimal::round($cogsTotal),
            ])->save();

            return $return->load('items');
        });
    }

    /**
     * The sale total split across lines in proportion to their line totals, by largest
     * remainder, so the shares are whole rupiah and add up to the total exactly.
     *
     * @param  array<int, SaleItem>  $items
     * @return array<int, int> sale_item_id => share
     */
    public static function lineShares(Sale $sale, array $items): array
    {
        return Apportion::byWeight($sale->total, array_map(fn (SaleItem $i) => $i->line_total, $items));
    }

    /**
     * PPN inside the refund, pro rata to the sale's PPN. The return that brings the refunds
     * to the full sale total takes whatever PPN is left, so nothing drifts.
     */
    private function taxPart(Sale $sale, int $refund, int $currentReturnId): int
    {
        if ($sale->tax_amount === 0 || $sale->total === 0) {
            return 0;
        }

        $earlier = $sale->returns()->whereKeyNot($currentReturnId);
        $refundedBefore = (int) (clone $earlier)->sum('total');
        $taxBefore = (int) (clone $earlier)->sum('tax_amount');

        if ($refundedBefore + $refund >= $sale->total) {
            return $sale->tax_amount - $taxBefore;
        }

        return intdiv(2 * $refund * $sale->tax_amount + $sale->total, 2 * $sale->total);
    }
}
