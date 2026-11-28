<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $category_id
 * @property int $base_unit_id
 * @property string $sku
 * @property string $name
 * @property int $min_stock
 * @property int $stock_on_hand Cache of SUM(stock_movements.qty); written only by StockLedger.
 * @property string $avg_cost Moving-average HPP per base unit; written only by StockLedger.
 * @property bool $is_active
 * @property bool $tracks_expiry Batch & expiry tracking (DESIGN.md §19); switched only through StockLedger.
 * @property-read int|string|null $sellable_stock Only when queried withSellableStock(); PostgreSQL may return a string.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['category_id', 'base_unit_id', 'sku', 'name', 'min_stock', 'is_active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'min_stock' => 'integer',
            'stock_on_hand' => 'integer',
            'avg_cost' => 'decimal:4',
            'is_active' => 'boolean',
            'tracks_expiry' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /** @return HasMany<ProductUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<StockBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    /**
     * Products at or below their minimum, judged on sellable stock: expired
     * batches still on the shelf cannot be sold, so they do not keep a product off the list.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeLowStock(Builder $query, ?CarbonInterface $on = null): void
    {
        $query->where('products.min_stock', '>=', self::sellableStock($on ?? now()));
    }

    /**
     * Adds `sellable_stock`: what may still be sold on $on. For goods that track expiry that
     * is their unexpired batches (the till's figure, DESIGN.md §19); for the rest, stock on hand.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWithSellableStock(Builder $query, ?CarbonInterface $on = null): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('products.*');
        }
        $query->selectSub(self::sellableStock($on ?? now()), 'sellable_stock');
    }

    /**
     * Correlated subquery for one product's sellable stock, using the same
     * StockBatch::sellableOn() as the till and the ledger. An aggregate without GROUP BY
     * always returns one row, so products without batches still get their stock on hand.
     *
     * @return Builder<StockBatch>
     */
    private static function sellableStock(CarbonInterface $on): Builder
    {
        return StockBatch::query()
            ->selectRaw('CASE WHEN products.tracks_expiry THEN COALESCE(SUM(stock_batches.qty_on_hand), 0) ELSE products.stock_on_hand END')
            ->whereColumn('stock_batches.product_id', 'products.id')
            ->where('stock_batches.qty_on_hand', '>', 0)
            ->sellableOn($on);
    }
}
