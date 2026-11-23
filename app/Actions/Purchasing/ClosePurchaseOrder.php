<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends a partly received order: what is still outstanding will not come.
 */
final class ClosePurchaseOrder
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

            if ($locked->status === PurchaseOrderStatus::Ordered) {
                $this->fail('Belum ada barang yang diterima; batalkan saja pesanannya.');
            }
            if ($locked->status !== PurchaseOrderStatus::Partial) {
                $this->fail("Pesanan berstatus {$locked->status->label()} tidak bisa ditutup.");
            }

            $locked->forceFill([
                'status' => PurchaseOrderStatus::Closed,
                'close_reason' => $reason,
                'finished_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
