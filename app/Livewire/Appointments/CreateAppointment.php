<?php

declare(strict_types=1);

namespace App\Livewire\Appointments;

use App\Domain\Appointments\Actions\ScheduleAppointment;
use App\Domain\Appointments\Enums\LocationType;
use App\Domain\Clients\Queries\SearchClients;
use App\Livewire\Forms\ClientForm;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Support\DisplayTimezone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CreateAppointment extends Component
{
    #[Locked]
    public ?int $client_id = null;

    public string $client_search = '';

    public bool $creating_client = false;

    public ClientForm $new_client;

    public ?int $service_id = null;

    public string $service_details = '';

    public string $date = '';

    public string $start_time = '';

    public string $end_time = '';

    public ?int $responsible_user_id = null;

    public string $location_type = LocationType::OnCampus->value;

    public string $location = '';

    public string $notes = '';

    /**
     * @param  ?string  $start  local wall time from the agenda, e.g. 2026-10-05T14:00:00
     */
    public function mount(?string $start = null, ?string $end = null, ?int $clientId = null): void
    {
        $startsAt = $start ? Carbon::parse($start) : DisplayTimezone::toLocal(now())->addHour()->startOfHour();
        $endsAt = $end ? Carbon::parse($end) : $startsAt->copy()->addHour();

        $this->date = $startsAt->format('Y-m-d');
        $this->start_time = $startsAt->format('H:i');
        $this->end_time = $endsAt->format('H:i');
        $this->responsible_user_id = Auth::user()->canWrite() ? Auth::id() : null;

        // A link to an inactive or removed client opens the form without one.
        if ($clientId !== null && Client::query()->active()->whereKey($clientId)->exists()) {
            $this->selectClient($clientId);
        }
    }

    #[Computed]
    public function client(): ?Client
    {
        return $this->client_id ? Client::query()->find($this->client_id) : null;
    }

    /**
     * @return Collection<int, Client>
     */
    #[Computed]
    public function clientResults(): Collection
    {
        if (mb_strlen(trim($this->client_search)) < 2) {
            return new Collection;
        }

        return SearchClients::query($this->client_search)->active()->limit(6)->get();
    }

    /**
     * @return Collection<int, ServiceCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return ServiceCategory::query()
            ->where('active', true)
            ->with(['services' => fn ($query) => $query->active()])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (ServiceCategory $category): bool => $category->services->isNotEmpty());
    }

    #[Computed]
    public function service(): ?Service
    {
        return $this->service_id ? Service::query()->with('documents')->find($this->service_id) : null;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function responsibles(): Collection
    {
        return User::query()->assignable()->orderBy('name')->get(['id', 'name']);
    }

    public function selectClient(int $clientId): void
    {
        $this->client_id = Client::query()->active()->findOrFail($clientId)->id;
        $this->client_search = '';
        $this->creating_client = false;
        $this->resetErrorBag('client_id');
    }

    public function clearClient(): void
    {
        $this->client_id = null;
    }

    public function startClientCreation(): void
    {
        $this->authorize('create', Client::class);

        $this->creating_client = true;
        $this->new_client->name = $this->client_search;
    }

    public function saveNewClient(): void
    {
        $this->authorize('create', Client::class);

        $this->selectClient($this->new_client->save()->id);
        $this->new_client->reset();
    }

    public function updatedServiceId(): void
    {
        $this->resetErrorBag(['service_id', 'service_details']);

        if ($this->service === null || ! $this->isTime($this->start_time)) {
            return;
        }

        $this->end_time = Carbon::createFromFormat('H:i', $this->start_time)
            ->addMinutes($this->service->default_duration_minutes)
            ->format('H:i');
    }

    public function updatingStartTime(string $value): void
    {
        if (! $this->isTime($value) || ! $this->isTime($this->start_time) || ! $this->isTime($this->end_time)) {
            return;
        }

        $duration = Carbon::createFromFormat('H:i', $this->start_time)
            ->diffInMinutes(Carbon::createFromFormat('H:i', $this->end_time));

        $this->end_time = Carbon::createFromFormat('H:i', $value)->addMinutes(max($duration, 15))->format('H:i');
    }

    public function save(ScheduleAppointment $schedule): void
    {
        $this->authorize('create', Appointment::class);

        $this->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('active', true)->whereNull('deleted_at')],
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')],
            'service_details' => ['nullable', 'string', 'max:2000'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:-1 year', 'before_or_equal:+2 years'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'responsible_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'location_type' => ['nullable', Rule::enum(LocationType::class)],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $appointment = $schedule->handle(Auth::user(), [
            'client_id' => $this->client_id,
            'service_id' => $this->service_id,
            'responsible_user_id' => $this->responsible_user_id,
            'starts_at' => DisplayTimezone::toUtc("{$this->date} {$this->start_time}"),
            'ends_at' => DisplayTimezone::toUtc("{$this->date} {$this->end_time}"),
            'service_details' => $this->service_details ?: null,
            'location_type' => $this->location_type ?: null,
            'location' => $this->location ?: null,
            'notes' => $this->notes ?: null,
        ]);

        session()->flash('notice', __('appointments.flash.scheduled'));

        $this->redirectRoute('appointments.show', $appointment);
    }

    public function render(): View
    {
        return view('livewire.appointments.create-appointment', [
            'locationTypes' => LocationType::cases(),
        ]);
    }

    private function isTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
