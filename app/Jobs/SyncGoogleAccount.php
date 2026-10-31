<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Google\GoogleAccessRevoked;
use App\Domain\Google\GoogleCalendar;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

// Right after connecting: creates the Caderneta SEF calendar and fills it
// with the person's upcoming appointments.
final class SyncGoogleAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public int $accountId) {}

    public function handle(GoogleCalendar $calendar): void
    {
        $account = GoogleAccount::query()->working()->find($this->accountId);

        if ($account === null) {
            return;
        }

        try {
            $calendar->calendarFor($account);
        } catch (GoogleAccessRevoked) {
            return;
        }

        Appointment::query()
            ->where('responsible_user_id', $account->user_id)
            ->where('ends_at', '>=', now()->subDay())
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->pluck('id')
            ->each(fn (int $id) => SyncAppointmentToGoogle::dispatch($id));

        if ($account->source_calendar_id !== null) {
            PullGoogleEvents::dispatch($account->id);
        }
    }
}
