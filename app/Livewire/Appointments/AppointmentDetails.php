<?php

declare(strict_types=1);

namespace App\Livewire\Appointments;

use App\Domain\Appointments\Actions\ChangeAppointmentStatus;
use App\Domain\Appointments\Actions\MarkDocumentReceived;
use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Actions\SendAppointmentReminder;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Support\DisplayTimezone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class AppointmentDetails extends Component
{
    public Appointment $appointment;

    public bool $editing_schedule = false;

    public string $date = '';

    public string $start_time = '';

    public string $end_time = '';

    public ?int $responsible_user_id = null;

    public ?string $message = null;

    public function mount(): void
    {
        $this->fillSchedule();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function responsibles(): Collection
    {
        return User::query()->assignable()->orderBy('name')->get(['id', 'name']);
    }

    public function changeStatus(string $status, ChangeAppointmentStatus $change): void
    {
        $next = AppointmentStatus::tryFrom($status) ?? abort(400);

        $this->authorize($next === AppointmentStatus::Cancelled ? 'cancel' : 'update', $this->appointment);

        $change->handle(Auth::user(), $this->appointment, $next);

        $this->message = __('appointments.flash.status_changed', ['status' => $next->label()]);
    }

    public function markDocument(int $documentId, bool $received, MarkDocumentReceived $mark): void
    {
        $this->authorize('update', $this->appointment);

        $mark->handle($this->appointment->documents()->findOrFail($documentId), $received);
    }

    public function editSchedule(): void
    {
        $this->authorize('update', $this->appointment);

        $this->fillSchedule();
        $this->editing_schedule = true;
    }

    public function saveSchedule(RescheduleAppointment $reschedule): void
    {
        $this->authorize('update', $this->appointment);

        $this->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:-1 year', 'before_or_equal:+2 years'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'responsible_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $reschedule->handle(
            Auth::user(),
            $this->appointment,
            DisplayTimezone::toUtc("{$this->date} {$this->start_time}"),
            DisplayTimezone::toUtc("{$this->date} {$this->end_time}"),
            User::query()->findOrFail($this->responsible_user_id),
        );

        $this->editing_schedule = false;
        $this->message = __('appointments.flash.rescheduled');
    }

    public function sendReminder(SendAppointmentReminder $send): void
    {
        $this->authorize('update', $this->appointment);

        $this->message = $send->handle($this->appointment, again: true)
            ? __('reminders.card.sent', ['email' => $this->appointment->client->email])
            : __('reminders.card.not_sent');
    }

    public function render(): View
    {
        $this->appointment->load([
            'client', 'service.category', 'responsible', 'documents', 'activities.user', 'externalForms',
            'googleEventCopies', 'googleEvent.account.user',
        ]);

        return view('livewire.appointments.appointment-details', [
            'start' => DisplayTimezone::toLocal($this->appointment->starts_at),
            'end' => DisplayTimezone::toLocal($this->appointment->ends_at),
            'usersById' => User::query()->whereIn('id', $this->mentionedUserIds())->pluck('name', 'id'),
        ]);
    }

    private function fillSchedule(): void
    {
        $start = DisplayTimezone::toLocal($this->appointment->starts_at);

        $this->date = $start->format('Y-m-d');
        $this->start_time = $start->format('H:i');
        $this->end_time = DisplayTimezone::toLocal($this->appointment->ends_at)->format('H:i');
        $this->responsible_user_id = $this->appointment->responsible_user_id;
    }

    private function mentionedUserIds(): array
    {
        return $this->appointment->activities
            ->flatMap(fn ($activity): array => [
                $activity->old_values['responsible_user_id'] ?? null,
                $activity->new_values['responsible_user_id'] ?? null,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
