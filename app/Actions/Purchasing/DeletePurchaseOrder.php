<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

final class DeletePurchaseOrder
{
    use LocksPurchaseOrder;

    public function handle(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = $this->lock($order);

            if ($locked->status !== PurchaseOrderStatus::Draft) {
                $this->fail('Hanya draft yang bisa dihapus.');
            }

            $locked->delete();
        });
    }
}
