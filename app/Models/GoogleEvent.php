<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'google_account_id', 'event_id', 'title', 'location', 'starts_at', 'ends_at', 'html_link',
    'appointment_id', 'dismissed_at', 'removed_at', 'sync_error',
])]
class GoogleEvent extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GoogleAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class, 'google_account_id');
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function isPending(): bool
    {
        return $this->appointment_id === null && $this->dismissed_at === null;
    }

    /**
     * Events nobody has turned into an appointment or set aside yet.
     *
     * @param  Builder<GoogleEvent>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('appointment_id')->whereNull('dismissed_at');
    }

    /**
     * @param  Builder<GoogleEvent>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $query->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt);
    }
}
