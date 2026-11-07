<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Domain\Appointments\Actions\SetReminderDays;
use App\Domain\Appointments\ReminderSchedule;
use App\Models\Setting;
use Illuminate\View\View;
use Livewire\Component;

class ReminderSettings extends Component
{
    public int|string $days = ReminderSchedule::DEFAULT_DAYS;

    public ?string $message = null;

    public function mount(): void
    {
        $this->authorize('update', Setting::class);
        $this->days = ReminderSchedule::daysBefore();
    }

    public function save(SetReminderDays $setDays): void
    {
        $this->authorize('update', Setting::class);
        $this->resetErrorBag();
        $this->message = null;

        $this->days = $setDays->handle(auth()->user(), $this->days);
        $this->message = trans_choice('reminders.settings.saved', $this->days, ['count' => $this->days]);
    }

    public function render(): View
    {
        return view('livewire.settings.reminder-settings', [
            'max' => ReminderSchedule::MAX_DAYS,
        ]);
    }
}
