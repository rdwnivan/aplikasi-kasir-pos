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
 * @property int $user_id
 * @property string $status draft | posted
 * @property string|null $note
 * @property Carbon|null $posted_at
 */
#[Fillable(['number', 'user_id', 'status', 'note'])]
class StockOpname extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /** @return HasMany<StockOpnameItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
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
