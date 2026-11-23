<?php

namespace App\Queries;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

/**
 * Orders still waiting for goods, for the stock dashboard.
 */
final class OpenPurchaseOrders
{
    /** @return array{count: int, late: int, outstandingValue: int} */
    public static function summary(): array
    {
        $orders = PurchaseOrder::query()
            ->whereIn('status', PurchaseOrderStatus::open())
            ->with(['items' => fn ($q) => $q->withSum('receiptItems', 'qty')])
            ->get();

        return [
            'count' => $orders->count(),
            'late' => $orders->filter(fn (PurchaseOrder $o) => $o->isLate())->count(),
            'outstandingValue' => (int) $orders->sum(fn (PurchaseOrder $o) => self::outstandingValue($o)),
        ];
    }

    /** Outstanding quantity × agreed price; needs items loaded withSum('receiptItems', 'qty'). */
    public static function outstandingValue(PurchaseOrder $order): int
    {
        return (int) $order->items->sum(fn (PurchaseOrderItem $i) => max(0, $i->qty - self::received($i)) * $i->unit_price);
    }

    /** Received value at the agreed price; needs items loaded withSum('receiptItems', 'qty'). */
    public static function receivedValue(PurchaseOrder $order): int
    {
        return (int) $order->items->sum(fn (PurchaseOrderItem $i) => min($i->qty, self::received($i)) * $i->unit_price);
    }

    public static function received(PurchaseOrderItem $item): int
    {
        return (int) ($item->getAttribute('receipt_items_sum_qty') ?? 0);
    }
}
