<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $number
 * @property int $shift_id
 * @property int $user_id
 * @property SaleStatus $status
 * @property string|null $held_label
 * @property int $subtotal
 * @property DiscountType|null $discount_type
 * @property int|null $discount_value
 * @property int $discount_amount
 * @property bool $prices_include_tax
 * @property string $tax_rate
 * @property int $taxable_amount
 * @property int $dpp_amount
 * @property int $tax_amount
 * @property int $total
 * @property PaymentMethod|null $payment_method
 * @property int|null $split_cash_amount Cash part of a `split` payment; null otherwise.
 * @property int|null $paid_amount
 * @property int|null $change_amount
 * @property string $cogs_total
 * @property Carbon|null $completed_at
 * @property string|null $payment_reference Midtrans transaction_id for QRIS.
 * @property string|null $qr_string
 * @property string|null $qr_url
 * @property Carbon|null $payment_expires_at
 * @property Carbon|null $payment_checked_at
 */
class Sale extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'discount_type' => DiscountType::class,
            'payment_method' => PaymentMethod::class,
            'prices_include_tax' => 'boolean',
            'tax_rate' => 'decimal:2',
            'cogs_total' => 'decimal:4',
            'completed_at' => 'datetime',
            'payment_expires_at' => 'datetime',
            'payment_checked_at' => 'datetime',
            ...array_fill_keys([
                'subtotal', 'discount_value', 'discount_amount', 'taxable_amount', 'dpp_amount',
                'tax_amount', 'total', 'paid_amount', 'change_amount', 'split_cash_amount',
            ], 'integer'),
        ];
    }

    /** What went into the drawer: the whole total for cash, the cash part for split. */
    public function cashPart(): int
    {
        return match ($this->payment_method) {
            PaymentMethod::Cash => $this->total,
            PaymentMethod::Split => (int) $this->split_cash_amount,
            default => 0,
        };
    }

    /** What is (or was to be) paid through Midtrans. */
    public function qrisPart(): int
    {
        return match ($this->payment_method) {
            PaymentMethod::Qris => $this->total,
            PaymentMethod::Split => $this->total - (int) $this->split_cash_amount,
            default => 0,
        };
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
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

    /** @return HasMany<SaleReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /** @return MorphMany<StockMovement, $this> */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
