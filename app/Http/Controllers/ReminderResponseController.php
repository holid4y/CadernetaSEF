<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Appointments\Actions\ChangeAppointmentStatus;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Support\DisplayTimezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

// Opening the link changes nothing: mail scanners open links on their own.
class ReminderResponseController extends Controller
{
    public function show(Appointment $appointment): View
    {
        $appointment->load(['client', 'service', 'documents']);

        return view('reminders.respond', [
            'appointment' => $appointment,
            'start' => DisplayTimezone::toLocal($appointment->starts_at),
            'end' => DisplayTimezone::toLocal($appointment->ends_at),
            'answers' => self::answers($appointment),
        ]);
    }

    public function store(Request $request, Appointment $appointment, ChangeAppointmentStatus $change): RedirectResponse
    {
        $answer = $request->validate([
            'answer' => ['required', Rule::in(['confirm', 'cancel'])],
        ])['answer'];

        $next = $answer === 'confirm' ? AppointmentStatus::Confirmed : AppointmentStatus::Cancelled;

        if (! in_array($next, self::answers($appointment), true)) {
            return redirect()->to($request->fullUrl())->with('notice', __('reminders.page.unchanged'));
        }

        $change->handle(null, $appointment, $next);

        return redirect()->to($request->fullUrl())->with('notice', __("reminders.page.done_{$answer}"));
    }

    /**
     * A link from an older reminder outlives a move to an earlier time, and
     * the team may open the appointment before it starts. From then on only
     * the team changes it.
     *
     * @return list<AppointmentStatus>
     */
    private static function answers(Appointment $appointment): array
    {
        $open = in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed], true);

        if (! $open || ! $appointment->starts_at->isFuture()) {
            return [];
        }

        return array_values(array_filter(
            [AppointmentStatus::Confirmed, AppointmentStatus::Cancelled],
            fn (AppointmentStatus $next): bool => $appointment->status->canTransitionTo($next),
        ));
    }
}
