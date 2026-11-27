<?php

namespace App\Models;

use App\Enums\CustomerPaymentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A customer paying off kasbon (pelunasan), in cash into the receiving user's open shift, or
 * by QRIS (DESIGN.md §18), which waits as pending_payment until Midtrans settles it.
 *
 * @property int $id
 * @property string $number PAY-YYYYMMDD-0001, also the Midtrans order_id for QRIS
 * @property int $customer_id
 * @property int $shift_id
 * @property int $user_id
 * @property int $amount
 * @property string $method self::CASH or self::QRIS
 * @property CustomerPaymentStatus $status
 * @property string|null $payment_reference Midtrans transaction_id
 * @property string|null $qr_string
 * @property string|null $qr_url
 * @property Carbon|null $payment_expires_at
 * @property Carbon|null $payment_checked_at
 * @property Carbon|null $completed_at null on cash rows written before §18
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class CustomerPayment extends Model
{
    public const CASH = 'cash';

    public const QRIS = 'qris';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => CustomerPaymentStatus::class,
            'payment_expires_at' => 'datetime',
            'payment_checked_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'method' => self::CASH,
        'status' => 'completed',
    ];

    public function isQris(): bool
    {
        return $this->method === self::QRIS;
    }

    /** When the money came in: settlement for QRIS, creation for cash. */
    public function receivedAt(): ?CarbonInterface
    {
        return $this->completed_at ?? $this->created_at;
    }

    /**
     * Payments whose money has come in. Every sum over customer_payments goes through this,
     * so a waiting or cancelled QRIS never counts.
     *
     * @param  Builder<CustomerPayment>  $query
     */
    public function scopeReceived(Builder $query): void
    {
        $query->where('status', CustomerPaymentStatus::Completed->value);
    }

    /** @param Builder<CustomerPayment> $query */
    public function scopeWaiting(Builder $query): void
    {
        $query->where('status', CustomerPaymentStatus::PendingPayment->value);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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

    /** @return MorphMany<CustomerLedgerEntry, $this> The kasbon this payment went to. */
    public function entries(): MorphMany
    {
        return $this->morphMany(CustomerLedgerEntry::class, 'reference');
    }
}
