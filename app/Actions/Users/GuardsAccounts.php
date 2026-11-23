<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\Shift;
use App\Models\User;
use App\Support\DemoAccounts;
use Illuminate\Validation\ValidationException;

/**
 * The rules every account change obeys, checked here rather than only hidden in the UI.
 */
trait GuardsAccounts
{
    protected function fail(string $message, string $field = 'user'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    protected function ensureNotProtected(User $user): void
    {
        if (DemoAccounts::isProtected($user)) {
            $this->fail(DemoAccounts::MESSAGE);
        }
    }

    protected function hasOpenShift(User $user): bool
    {
        return Shift::query()->open()->where('user_id', $user->id)->exists();
    }

    /**
     * Call inside the transaction, before taking $user out of the active owners. Locks the
     * active owners' rows so two owners removing each other at once cannot both succeed.
     */
    protected function ensureAnotherActiveOwner(User $user, string $field = 'user'): void
    {
        $others = User::role(Role::Owner->value)
            ->whereNull('deactivated_at')
            ->lockForUpdate()
            ->pluck('id')
            ->reject(fn (int $id) => $id === $user->id);

        if ($others->isEmpty()) {
            $this->fail('Harus ada minimal satu owner aktif.', $field);
        }
    }

    /**
     * Remember-me cookies stop working. Open sessions are ended by middleware on their next
     * request (EnsureUserIsActive, AuthenticateSession), not by deleting session rows here:
     * that way the user is told why they were logged out, whatever the session driver.
     */
    protected function endSessions(User $user): void
    {
        $user->setRememberToken(bin2hex(random_bytes(30)));
    }
}
