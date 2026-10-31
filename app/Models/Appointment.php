<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Appointments\Enums\ActivityType;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Enums\LocationType;
use App\Jobs\SyncAppointmentToGoogle;
use App\Support\DisplayTimezone;
use Carbon\CarbonInterface;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'client_id', 'service_id', 'service_details', 'responsible_user_id',
    'starts_at', 'ends_at', 'status', 'location_type', 'location', 'notes',
    'created_by', 'updated_by',
])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory, SoftDeletes;

    // What the copy in the responsible's Google calendar shows.
    private const SHOWN_IN_GOOGLE = [
        'client_id', 'service_id', 'responsible_user_id', 'starts_at', 'ends_at',
        'status', 'location_type', 'location',
    ];

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment): void {
            $appointment->integration_id = (string) Str::uuid();
        });

        static::saved(function (Appointment $appointment): void {
            if ($appointment->wasRecentlyCreated || $appointment->wasChanged(self::SHOWN_IN_GOOGLE)) {
                SyncAppointmentToGoogle::dispatchFor($appointment);
            }
        });

        static::deleted(fn (Appointment $appointment) => SyncAppointmentToGoogle::dispatchFor($appointment));
        static::restored(fn (Appointment $appointment) => SyncAppointmentToGoogle::dispatchFor($appointment));
    }

    public function googleEventCopies(): HasMany
    {
        return $this->hasMany(GoogleEventCopy::class);
    }

    public function googleEvent(): HasOne
    {
        return $this->hasOne(GoogleEvent::class);
    }

    public function externalForms(): HasMany
    {
        return $this->hasMany(ExternalForm::class)->latest('source_updated_at');
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'location_type' => LocationType::class,
            'reminder_sent_at' => 'datetime',
            'service_details' => 'encrypted',
            'notes' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * @return HasMany<AppointmentDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(AppointmentDocument::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<AppointmentActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(AppointmentActivity::class)->latest('created_at')->latest('id');
    }

    protected function weekday(): Attribute
    {
        return Attribute::get(
            fn (): string => DisplayTimezone::toLocal($this->starts_at)->translatedFormat('l'),
        );
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function recordActivity(ActivityType $type, ?User $user, array $old = [], array $new = []): AppointmentActivity
    {
        return $this->activities()->create([
            'user_id' => $user?->id,
            'event_type' => $type,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
        ]);
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    public function scopeWithPendingDocumentsCount(Builder $query): void
    {
        $query->withCount(['documents as pending_documents_count' => fn (Builder $documents) => $documents->whereNull('received_at')]);
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    public function scopeBlocking(Builder $query): void
    {
        $query->whereIn('status', AppointmentStatus::blocking());
    }

    /**
     * Periods that only touch (10:00–11:00 and 11:00–12:00) do not overlap.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $query->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt);
    }
}
