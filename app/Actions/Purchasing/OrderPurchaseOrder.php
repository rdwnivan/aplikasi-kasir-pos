<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Documents\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Places a draft with the supplier: it gets its PO number now (drafts that are thrown away
 * never use one up) and its lines can no longer change.
 */
final class OrderPurchaseOrder
{
    use LocksPurchaseOrder;

    public function __construct(private readonly DocumentNumber $numbers) {}

    public function handle(PurchaseOrder $order, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $locked = $this->lock($order);

            if ($locked->status !== PurchaseOrderStatus::Draft) {
                $this->fail("Pesanan {$locked->number} sudah dipesan.");
            }
            if (! $locked->items()->exists()) {
                $this->fail('Minimal satu barang harus diisi.');
            }

            $now = now();
            $locked->forceFill([
                'number' => $this->numbers->next(DocumentNumber::PURCHASE_ORDER, $now),
                'status' => PurchaseOrderStatus::Ordered,
                'ordered_at' => $now,
            ])->save();

            return $locked->load('items');
        });
    }
}
