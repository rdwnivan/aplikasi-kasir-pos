<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * An owner writing off kasbon that will not be paid. A bad-debt expense: it does not
 * reduce sales or gross profit (DESIGN.md §17.2).
 *
 * @property int $id
 * @property int $customer_id
 * @property int $user_id
 * @property int $amount
 * @property string $reason
 * @property Carbon|null $created_at
 */
class CustomerWriteOff extends Model
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphMany<CustomerLedgerEntry, $this> */
    public function entries(): MorphMany
    {
        return $this->morphMany(CustomerLedgerEntry::class, 'reference');
    }
}
