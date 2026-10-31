<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Users\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasOne<GoogleAccount, $this>
     */
    public function googleAccount(): HasOne
    {
        return $this->hasOne(GoogleAccount::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function canWrite(): bool
    {
        return $this->active && $this->role->canWrite();
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeAssignable(Builder $query): void
    {
        $query->where('active', true)->where('role', '!=', UserRole::Viewer);
    }

    // Ends the mobile tokens and the web sessions; the session in use can be kept.
    public function signOutEverywhere(?string $except = null): void
    {
        $this->tokens()->delete();
        DB::table('sessions')
            ->where('user_id', $this->id)
            ->when($except, fn ($query, string $id) => $query->where('id', '!=', $id))
            ->delete();
    }
}
