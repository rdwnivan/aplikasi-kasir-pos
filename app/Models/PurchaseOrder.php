<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $number
 * @property int $supplier_id
 * @property int $user_id
 * @property PurchaseOrderStatus $status
 * @property Carbon|null $expected_at
 * @property Carbon|null $ordered_at
 * @property Carbon|null $finished_at
 * @property int $total Rupiah, at the agreed prices
 * @property string|null $note
 * @property string|null $close_reason
 */
#[Fillable(['supplier_id', 'user_id', 'status', 'expected_at', 'total', 'note'])]
class PurchaseOrder extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'expected_at' => 'date',
            'ordered_at' => 'datetime',
            'finished_at' => 'datetime',
            'total' => 'integer',
        ];
    }

    public function isLate(?Carbon $today = null): bool
    {
        return $this->status->isOpen()
            && $this->expected_at !== null
            && $this->expected_at->lt(($today ?? now())->startOfDay());
    }

    /**
     * Quantity received so far per line, in the ordered unit. Derived from the receipt lines,
     * which are the only record of what arrived.
     *
     * @return array<int, int> purchase_order_item_id => qty
     */
    public function receivedQuantities(): array
    {
        $received = GoodsReceiptItem::query()
            ->whereIn('purchase_order_item_id', $this->items()->select('id'))
            ->groupBy('purchase_order_item_id')
            ->selectRaw('purchase_order_item_id, SUM(qty) as received')
            ->pluck('received', 'purchase_order_item_id');

        return $this->items()->orderBy('id')->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => (int) ($received[$id] ?? 0)])
            ->all();
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
