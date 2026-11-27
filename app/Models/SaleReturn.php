<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int $sale_id
 * @property int $shift_id
 * @property int $user_id
 * @property int $approved_by
 * @property int $total Value returned, Rupiah (reduces sales).
 * @property int $credit_amount Part taken off the customer's kasbon instead of paid out (DESIGN.md §17).
 * @property int $tax_amount
 * @property string $cogs_total
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
class SaleReturn extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'credit_amount' => 'integer',
            'tax_amount' => 'integer',
            'cogs_total' => 'decimal:4',
        ];
    }

    /** Cash paid out of the drawer: the return value less what came off a kasbon debt. */
    public function cashRefund(): int
    {
        return $this->total - $this->credit_amount;
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<SaleReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    /** @return MorphMany<StockMovement, $this> */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
