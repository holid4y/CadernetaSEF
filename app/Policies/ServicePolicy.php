<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->active;
    }

    public function create(User $user): bool
    {
        return $user->active && $user->isAdmin();
    }

    public function update(User $user, Service $service): bool
    {
        return $user->active && $user->isAdmin();
    }

    public function delete(User $user, Service $service): bool
    {
        return $user->active && $user->isAdmin();
    }
}
