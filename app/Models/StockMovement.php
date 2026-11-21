<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One immutable row in the stock ledger. Create only through App\Services\Inventory\StockLedger.
 *
 * @property int $id
 * @property int $product_id
 * @property StockMovementType $type
 * @property int $qty Signed, in base units.
 * @property string $unit_cost HPP per base unit for this movement.
 * @property int $stock_after
 * @property string $avg_cost_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $user_id
 * @property string|null $note
 * @property Carbon $created_at
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'qty' => 'integer',
            'unit_cost' => 'decimal:4',
            'stock_after' => 'integer',
            'avg_cost_after' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Stock movements are immutable; record a correcting movement instead.'));
        static::deleting(fn () => throw new \LogicException('Stock movements are immutable; record a correcting movement instead.'));
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
