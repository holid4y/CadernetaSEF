<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Actions;

use App\Domain\Appointments\ReminderSchedule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

final class SetReminderDays
{
    public function handle(User $author, mixed $days): int
    {
        $data = Validator::make(['days' => $days], [
            'days' => ['required', 'integer', 'min:1', 'max:'.ReminderSchedule::MAX_DAYS],
        ])->validate();

        Setting::query()->updateOrCreate(
            ['key' => ReminderSchedule::KEY],
            ['value' => (string) $data['days'], 'updated_by' => $author->id],
        );

        return (int) $data['days'];
    }
}
