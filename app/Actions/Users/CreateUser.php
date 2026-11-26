<?php

namespace App\Actions\Users;

use App\Enums\AccountEventType;
use App\Enums\Role;
use App\Models\User;
use App\Services\Audit\AccountLog;
use Illuminate\Support\Facades\DB;

/**
 * An account made by an owner: verified (the owner vouches for the email; the app sends no
 * mail) and with a starting password its user must replace at the first login.
 */
final class CreateUser
{
    /** @param array{name: string, email: string, role: Role, password: string} $data */
    public function handle(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data) {
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->forceFill(['email_verified_at' => now(), 'must_change_password' => true])->save();
            $user->assignRole($data['role']->value);
            AccountLog::record($user, AccountEventType::Created, $actor, ['role' => $data['role']->value]);

            return $user;
        });
    }
}
