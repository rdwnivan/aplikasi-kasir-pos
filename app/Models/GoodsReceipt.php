<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int|null $supplier_id
 * @property int $user_id
 * @property Carbon $received_at
 * @property int $total Rupiah
 * @property string|null $note
 */
#[Fillable(['number', 'supplier_id', 'user_id', 'received_at', 'total', 'note'])]
class GoodsReceipt extends Model
{
    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'total' => 'integer',
        ];
    }

    /** @return HasMany<GoodsReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
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

    /** @return MorphMany<StockMovement, $this> */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
