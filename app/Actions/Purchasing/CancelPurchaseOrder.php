<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Calls off a placed order before anything has arrived. A draft is deleted instead, and an
 * order that has received goods is closed instead, so receipts never point at a cancelled order.
 */
final class CancelPurchaseOrder
{
    use LocksPurchaseOrder;

    public function handle(PurchaseOrder $order, User $user, string $reason): PurchaseOrder
    {
        $reason = trim($reason);
        if ($reason === '') {
            $this->fail('Alasan wajib diisi.', 'reason');
        }

        return DB::transaction(function () use ($order, $reason) {
            $locked = $this->lock($order);

            if ($locked->status === PurchaseOrderStatus::Partial) {
                $this->fail('Sudah ada barang yang diterima; tutup sisa pesanannya.');
            }
            if ($locked->status !== PurchaseOrderStatus::Ordered) {
                $this->fail("Pesanan berstatus {$locked->status->label()} tidak bisa dibatalkan.");
            }

            $locked->forceFill([
                'status' => PurchaseOrderStatus::Cancelled,
                'close_reason' => $reason,
                'finished_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
