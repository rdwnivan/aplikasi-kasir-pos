<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A customer paying off kasbon (pelunasan), in cash into the receiving user's open shift.
 *
 * @property int $id
 * @property string $number PAY-YYYYMMDD-0001
 * @property int $customer_id
 * @property int $shift_id
 * @property int $user_id
 * @property int $amount
 * @property string $method 'cash' (QRIS is on the roadmap, DESIGN.md §17.4)
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class CustomerPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
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
