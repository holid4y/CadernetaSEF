<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class SettingPolicy
{
    public function update(User $user): bool
    {
        return $user->active && $user->isAdmin();
    }
}
