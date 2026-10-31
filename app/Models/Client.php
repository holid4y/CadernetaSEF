<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Clients\Enums\ClientType;
use App\Jobs\SyncAppointmentToGoogle;
use App\Support\BlindIndex;
use App\Support\ContactLinks;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable([
    'type', 'name', 'trade_name', 'document', 'phone', 'email',
    'notes', 'accepts_reminders', 'active',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (Client $client): void {
            if ($client->isDirty('document')) {
                $client->document_index = BlindIndex::forDocument($client->document);
            }

            if ($client->isDirty('accepts_reminders')) {
                $client->reminders_consented_at = $client->accepts_reminders ? now() : null;
            }
        });

        // The name is in the title of the copies in Google.
        static::updated(function (Client $client): void {
            if (! $client->wasChanged('name')) {
                return;
            }

            GoogleEventCopy::query()
                ->whereIn('appointment_id', $client->appointments()->select('id'))
                ->distinct()
                ->pluck('appointment_id')
                ->each(fn (int $id) => SyncAppointmentToGoogle::dispatch($id)->afterCommit());
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ClientType::class,
            'document' => 'encrypted',
            'notes' => 'encrypted',
            'accepts_reminders' => 'boolean',
            'reminders_consented_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasOne<Appointment, $this>
     */
    public function nextAppointment(): HasOne
    {
        return $this->hasOne(Appointment::class)->ofMany(
            ['starts_at' => 'min'],
            fn (Builder $query) => $query->where('starts_at', '>=', now())->blocking(),
        );
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function recentAppointments(): HasMany
    {
        return $this->appointments()->where('starts_at', '<', now())->latest('starts_at')->limit(3);
    }

    public function greetingName(): string
    {
        return $this->isOrganization() ? ($this->trade_name ?: $this->name) : Str::before($this->name, ' ');
    }

    public function canReceiveReminders(): bool
    {
        return $this->active && $this->accepts_reminders && ContactLinks::email($this->email) !== null;
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    public function isOrganization(): bool
    {
        return $this->type === ClientType::Organization;
    }

    /**
     * @param  Builder<Client>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
