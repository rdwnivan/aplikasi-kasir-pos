<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * An owner's reset for someone who lost the phone holding their authenticator or passkey
 * (DESIGN.md §14): 2FA and every passkey go, so the account signs in with its password and
 * may set them up again. Open sessions keep running; a stolen device needs a password reset.
 */
final class ResetSignInFactors
{
    use GuardsAccounts;

    public function handle(User $actor, User $user): User
    {
        if ($actor->is($user)) {
            $this->fail('Atur 2FA dan passkey Anda sendiri di Pengaturan → Keamanan.');
        }
        $this->ensureNotProtected($user);

        return DB::transaction(function () use ($actor, $user) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $hadTwoFactor = $locked->hasTwoFactorState();
            $passkeys = $locked->passkeys()->count();

            if (! $hadTwoFactor && $passkeys === 0) {
                $this->fail("{$locked->name} tidak memakai 2FA maupun passkey.");
            }

            $locked->forgetSecondFactors();
            $this->endSessions($locked);
            $locked->save();

            Log::notice('Owner reset 2FA and passkeys', [
                'actor_id' => $actor->id,
                'user_id' => $locked->id,
                'had_two_factor' => $hadTwoFactor,
                'passkeys' => $passkeys,
            ]);

            return $locked;
        });
    }
}
