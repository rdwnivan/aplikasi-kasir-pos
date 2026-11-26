<?php

namespace App\Models;

use App\Enums\AccountEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to an account (DESIGN.md §15). Written only by App\Services\Audit\AccountLog;
 * never updated or deleted. `details` holds names, emails, roles, counts and flags, never a
 * secret.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $actor_id
 * @property AccountEventType $type
 * @property array<string, mixed>|null $details
 * @property Carbon $created_at
 */
class AccountEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => AccountEventType::class,
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
