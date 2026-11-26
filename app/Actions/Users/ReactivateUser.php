<?php

namespace App\Actions\Users;

use App\Enums\AccountEventType;
use App\Models\User;
use App\Services\Audit\AccountLog;
use Illuminate\Support\Facades\DB;

final class ReactivateUser
{
    public function handle(User $actor, User $user): User
    {
        if ($user->isActive()) {
            return $user;
        }

        return DB::transaction(function () use ($actor, $user) {
            $user->forceFill(['deactivated_at' => null])->save();
            AccountLog::record($user, AccountEventType::Reactivated, $actor);

            return $user;
        });
    }
}
