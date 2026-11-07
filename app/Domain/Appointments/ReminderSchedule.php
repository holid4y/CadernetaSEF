<?php

declare(strict_types=1);

namespace App\Domain\Appointments;

use App\Models\Appointment;
use App\Models\Setting;

// How many days before the appointment the reminder goes out. The
// coordination changes it in the settings; it starts at two days.
final class ReminderSchedule
{
    public const KEY = 'reminder_days_before';

    public const DEFAULT_DAYS = 2;

    public const MAX_DAYS = 14;

    public static function daysBefore(): int
    {
        return (int) Setting::read(self::KEY, (string) self::DEFAULT_DAYS);
    }

    // Booked or moved this close to the date, the confirmation is the reminder.
    public static function isDue(Appointment $appointment): bool
    {
        return $appointment->starts_at->lessThanOrEqualTo(now()->addDays(self::daysBefore()));
    }
}
