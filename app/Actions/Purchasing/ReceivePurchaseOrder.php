<?php

namespace App\Actions\Purchasing;

use App\Actions\Inventory\ReceiveGoods;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books a delivery against a placed order: an ordinary goods receipt (stock and HPP at the
 * invoice price, through ReceiveGoods) whose lines point at the order lines they fill. A line
 * may not take more than is still outstanding; extras go on a receipt without an order.
 */
final class ReceivePurchaseOrder
{
    use LocksPurchaseOrder;

    public function __construct(private readonly ReceiveGoods $receive) {}

    /**
     * @param  list<array{purchase_order_item_id: int, qty: int, unit_price: int, batches?: list<array{expires_on: string, qty: int, code?: string|null}>}>  $lines
     */
    public function handle(
        PurchaseOrder $order,
        User $user,
        array $lines,
        ?CarbonInterface $receivedAt = null,
        ?string $note = null,
    ): GoodsReceipt {
        return DB::transaction(function () use ($order, $user, $lines, $receivedAt, $note) {
            $locked = $this->lock($order);

            if (! $locked->status->isOpen()) {
                $this->fail("Pesanan berstatus {$locked->status->label()} tidak bisa menerima barang.");
            }

            $items = $locked->items()->get()->keyBy('id');
            $received = $locked->receivedQuantities();
            $errors = [];
            $seen = [];
            $receiptLines = [];

            foreach ($lines as $i => $line) {
                $item = $items->get($line['purchase_order_item_id']);

                if ($item === null) {
                    $errors["items.$i.purchase_order_item_id"] = 'Barang ini tidak ada di pesanan.';

                    continue;
                }
                if (isset($seen[$item->id])) {
                    $errors["items.$i.purchase_order_item_id"] = 'Barang ini muncul dua kali.';

                    continue;
                }
                $seen[$item->id] = true;

                $remaining = $item->qty - ($received[$item->id] ?? 0);

                if ($line['qty'] < 0) {
                    $errors["items.$i.qty"] = 'Jumlah tidak boleh negatif.';
                } elseif ($line['qty'] > $remaining) {
                    $errors["items.$i.qty"] = "Melebihi sisa pesanan ({$remaining}).";
                } elseif ($line['qty'] > 0) {
                    $receiptLines[] = [
                        'product_id' => $item->product_id,
                        'unit_id' => $item->unit_id,
                        'qty' => $line['qty'],
                        'unit_price' => $line['unit_price'],
                        'purchase_order_item_id' => $item->id,
                        // Expiry dates for goods that track them; errors point back at this line.
                        'batches' => $line['batches'] ?? [],
                        'line' => $i,
                    ];
                }
            }

            if ($errors === [] && $receiptLines === []) {
                $errors['items'] = 'Isi jumlah yang diterima untuk minimal satu barang.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $receipt = $this->receive->handle($user, $receiptLines, $locked->supplier_id, $receivedAt, $note, $locked);

            $received = $locked->receivedQuantities();
            $complete = $items->every(fn ($item) => ($received[$item->id] ?? 0) >= $item->qty);

            $locked->forceFill($complete
                ? ['status' => PurchaseOrderStatus::Received, 'finished_at' => now()]
                : ['status' => PurchaseOrderStatus::Partial])->save();

            return $receipt;
        });
    }
}
