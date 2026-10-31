<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'email', 'refresh_token', 'access_token', 'access_token_expires_at', 'scopes',
    'calendar_id', 'source_calendar_id', 'source_calendar_name', 'pulled_at', 'failed_at',
])]
#[Hidden(['refresh_token', 'access_token'])]
class GoogleAccount extends Model
{
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'pulled_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<GoogleEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(GoogleEvent::class);
    }

    /**
     * Accounts Google still accepts, of people who can still sign in.
     *
     * @param  Builder<GoogleAccount>  $query
     */
    public function scopeWorking(Builder $query): void
    {
        $query->whereNull('failed_at')->whereRelation('user', 'active', true);
    }
}
