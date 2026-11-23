<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Locks an account out without deleting it, so its sales, shifts and stock documents keep
 * their author. Its sessions end at once (and EnsureUserIsActive catches any that remain).
 */
final class DeactivateUser
{
    use GuardsAccounts;

    public function handle(User $actor, User $user): User
    {
        if ($actor->is($user)) {
            $this->fail('Anda tidak bisa menonaktifkan akun sendiri.');
        }
        $this->ensureNotProtected($user);

        return DB::transaction(function () use ($user) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $locked->isActive()) {
                return $locked;
            }
            if ($this->hasOpenShift($locked)) {
                $this->fail("{$locked->name} masih punya shift terbuka. Tutup shift dulu.");
            }
            if ($locked->hasRole(Role::Owner->value)) {
                $this->ensureAnotherActiveOwner($locked);
            }

            $locked->forceFill(['deactivated_at' => now()]);
            $this->endSessions($locked);
            $locked->save();

            return $locked;
        });
    }
}
