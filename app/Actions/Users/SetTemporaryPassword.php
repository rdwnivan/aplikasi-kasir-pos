<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An owner's reset for someone who forgot their password (the demo sends no email). The
 * user must pick their own at the next login, and sessions on the old password end.
 */
final class SetTemporaryPassword
{
    use GuardsAccounts;

    public function handle(User $actor, User $user, string $password): User
    {
        if ($actor->is($user)) {
            $this->fail('Ganti kata sandi Anda sendiri di Pengaturan → Keamanan.');
        }
        $this->ensureNotProtected($user);

        return DB::transaction(function () use ($user, $password) {
            $user->password = $password;
            $user->must_change_password = true;
            $this->endSessions($user);
            $user->save();

            return $user;
        });
    }
}
