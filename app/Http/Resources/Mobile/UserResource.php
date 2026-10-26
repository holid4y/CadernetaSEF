<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Domain\Users\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Colleagues' addresses are not needed by the app.
            'email' => $request->user()?->id === $this->id ? $this->email : null,
            'role' => self::role($this->role),
            'active' => $this->active,
        ];
    }

    public static function role(UserRole $role): string
    {
        return match ($role) {
            UserRole::Admin => 'admin',
            UserRole::Member => 'team',
            UserRole::Viewer => 'viewer',
        };
    }
}
