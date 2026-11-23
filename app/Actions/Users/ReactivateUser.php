<?php

namespace App\Actions\Users;

use App\Models\User;

final class ReactivateUser
{
    public function handle(User $user): User
    {
        $user->forceFill(['deactivated_at' => null])->save();

        return $user;
    }
}
