<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Owner approval by PIN, for actions a cashier may not do alone (returns). The PIN is matched
 * against every owner who has set one; wrong guesses are throttled per requesting user.
 */
final class OwnerPin
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 300;

    /** Returns the approving owner, or throws a validation error on `owner_pin`. */
    public function approve(User $requester, string $pin, string $field = 'owner_pin'): User
    {
        $key = 'owner-pin:'.$requester->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            throw ValidationException::withMessages([$field => "Terlalu banyak PIN salah. Coba lagi dalam {$minutes} menit."]);
        }

        $owners = User::role(Role::Owner->value)->whereNotNull('pin_hash')->get();

        if ($owners->isEmpty()) {
            throw ValidationException::withMessages([$field => 'Owner belum mengatur PIN. Atur dulu di Pengaturan → PIN owner.']);
        }

        foreach ($owners as $owner) {
            if ($owner->pin_hash !== null && Hash::check($pin, $owner->pin_hash)) {
                RateLimiter::clear($key);

                return $owner;
            }
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        throw ValidationException::withMessages([$field => 'PIN owner salah.']);
    }
}
