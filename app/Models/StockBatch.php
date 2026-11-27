<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A batch of one product with its expiry date (DESIGN.md §19). Created and changed only by
 * App\Services\Inventory\StockLedger, under the product's row lock.
 *
 * @property int $id
 * @property int $product_id
 * @property string|null $code
 * @property Carbon|null $expires_on null = a batch without a date (stock from before tracking)
 * @property int $qty_on_hand Cache of SUM(stock_movements.qty) for this batch.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StockBatch extends Model
{
    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SOON = 'soon';

    public const STATUS_OK = 'ok';

    public const STATUS_UNDATED = 'undated';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'qty_on_hand' => 'integer',
        ];
    }

    /** Expired once the date has passed: goods may still be sold on the date printed. */
    public function isExpired(?CarbonInterface $on = null): bool
    {
        return $this->expires_on !== null && $this->expires_on->toDateString() < ($on ?? now())->toDateString();
    }

    /** @return self::STATUS_* */
    public function status(?CarbonInterface $on = null): string
    {
        $on ??= now();
        $today = $on->toDateString();

        return match (true) {
            $this->expires_on === null => self::STATUS_UNDATED,
            $this->expires_on->toDateString() < $today => self::STATUS_EXPIRED,
            $this->expires_on->toDateString() <= Carbon::parse($today)->addDays(self::warnDays())->toDateString() => self::STATUS_SOON,
            default => self::STATUS_OK,
        };
    }

    /** Days ahead that count as "hampir kedaluwarsa" (config pos.expiry.warn_days). */
    public static function warnDays(): int
    {
        return max(0, (int) config('pos.expiry.warn_days', 30));
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_EXPIRED => 'Kedaluwarsa',
            self::STATUS_SOON => 'Hampir kedaluwarsa',
            self::STATUS_UNDATED => 'Tanpa tanggal',
            default => 'Aman',
        };
    }

    /** "12 Des 2026 · L42", or "Tanpa tanggal". */
    public function label(): string
    {
        $date = $this->expires_on?->settings(['locale' => 'id'])->translatedFormat('j M Y') ?? 'Tanpa tanggal';

        return $this->code === null ? $date : "{$date} · {$this->code}";
    }

    /**
     * First expired, first out: the batch without a date (old stock) first, then by date.
     *
     * @param  Builder<StockBatch>  $query
     */
    public function scopeFefo(Builder $query): void
    {
        $query->orderByRaw('CASE WHEN expires_on IS NULL THEN 0 ELSE 1 END')->orderBy('expires_on')->orderBy('id');
    }

    /**
     * Batches that may still be sold on $on (not expired).
     *
     * @param  Builder<StockBatch>  $query
     */
    public function scopeSellableOn(Builder $query, CarbonInterface $on): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $on->toDateString()));
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
