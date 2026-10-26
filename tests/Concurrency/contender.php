<?php

declare(strict_types=1);

// One of several processes started together by ConcurrentChangesTest. Each
// has its own database connection, like two people saving at the same time.

use App\Domain\Appointments\Actions\ChangeAppointmentStatus;
use App\Domain\Appointments\Actions\ScheduleAppointment;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $startAt, $action, $userId, $target, $value] = $argv;

usleep(max(0, (int) (((float) $startAt - microtime(true)) * 1_000_000)));

$author = User::query()->findOrFail((int) $userId);

try {
    match ($action) {
        'book' => app(ScheduleAppointment::class)->handle($author, [
            'client_id' => (int) $target,
            'service_id' => (int) $value,
            'responsible_user_id' => $author->id,
            'starts_at' => Carbon::parse('2026-10-06 13:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-10-06 14:00', 'UTC'),
        ]),
        'status' => app(ChangeAppointmentStatus::class)->handle(
            $author,
            Appointment::query()->findOrFail((int) $target),
            AppointmentStatus::from($value),
        ),
    };

    echo 'saved';
} catch (ValidationException $error) {
    echo "refused:{$error->status}";
}
