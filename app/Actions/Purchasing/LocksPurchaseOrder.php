<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrder;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a purchase order starts by locking its row and re-reading the status, so
 * two clerks acting on the same order take turns. The order is always locked before any
 * product row (ReceiveGoods locks products afterwards), keeping one lock order everywhere.
 */
trait LocksPurchaseOrder
{
    private function lock(PurchaseOrder $order): PurchaseOrder
    {
        /** @var PurchaseOrder */
        return PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function fail(string $message, string $key = 'order'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
