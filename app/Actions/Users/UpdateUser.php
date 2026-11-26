<?php

namespace App\Actions\Users;

use App\Enums\AccountEventType;
use App\Enums\Role;
use App\Models\User;
use App\Services\Audit\AccountLog;
use Illuminate\Support\Facades\DB;

/**
 * Name, email and role. An owner keeps their own role, the store keeps an active owner, and
 * someone with an open shift keeps a role that can reach the till to close it.
 */
final class UpdateUser
{
    use GuardsAccounts;

    /** @param array{name: string, email: string, role: Role} $data */
    public function handle(User $actor, User $user, array $data): User
    {
        $current = Role::tryFrom((string) $user->getRoleNames()->first());
        $role = $data['role'];

        if ($data['email'] !== $user->email || $role !== $current) {
            $this->ensureNotProtected($user);
        }

        if ($role !== $current) {
            if ($actor->is($user)) {
                $this->fail('Anda tidak bisa mengubah role akun sendiri.', 'role');
            }
            if ($role === Role::Warehouse && $this->hasOpenShift($user)) {
                $this->fail("{$user->name} masih punya shift terbuka. Tutup shift dulu.", 'role');
            }
        }

        return DB::transaction(function () use ($actor, $user, $data, $current, $role) {
            if ($current === Role::Owner && $role !== Role::Owner) {
                if ($user->isActive()) {
                    $this->ensureAnotherActiveOwner($user);
                }
                // Only owners approve returns; the PIN must not outlive the role.
                $user->pin_hash = null;
            }

            $user->fill(['name' => $data['name'], 'email' => $data['email']]);
            $changes = AccountLog::changes($user, ['name', 'email']);
            if ($role !== $current) {
                $changes['role'] = [$current?->value, $role->value];
            }
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()]);
            }
            $user->save();

            if ($role !== $current) {
                $user->syncRoles([$role->value]);
            }
            if ($changes !== []) {
                AccountLog::record($user, AccountEventType::Updated, $actor, $changes);
            }

            return $user;
        });
    }
}
