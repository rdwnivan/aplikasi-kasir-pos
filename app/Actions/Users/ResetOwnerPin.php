<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;

/**
 * Clears another owner's approval PIN (forgotten or leaked). Owners never set someone
 * else's PIN, because the PIN says who approved a return; that owner sets a new one.
 */
final class ResetOwnerPin
{
    use GuardsAccounts;

    public function handle(User $actor, User $user): User
    {
        if ($actor->is($user)) {
            $this->fail('Atur PIN Anda sendiri di Pengaturan → PIN owner.');
        }
        if (! $user->hasRole(Role::Owner->value)) {
            $this->fail("{$user->name} bukan owner, jadi tidak punya PIN.");
        }
        $this->ensureNotProtected($user);

        $user->forceFill(['pin_hash' => null])->save();

        return $user;
    }
}
