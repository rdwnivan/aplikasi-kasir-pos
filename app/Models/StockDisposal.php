<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Goods thrown away from their batches, mostly because they expired (DESIGN.md §19 P6c).
 * Each line is one `disposal` movement at the average HPP of the moment.
 *
 * @property int $id
 * @property string $number DSP-YYYYMMDD-0001
 * @property int $user_id
 * @property string $reason
 * @property int $total_value Σ line values, rupiah.
 * @property Carbon|null $created_at
 */
#[Fillable(['number', 'user_id', 'reason', 'total_value'])]
class StockDisposal extends Model
{
    protected function casts(): array
    {
        return ['total_value' => 'integer'];
    }

    /** @return HasMany<StockDisposalItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockDisposalItem::class);
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
