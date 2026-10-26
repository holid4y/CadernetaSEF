<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Actions;

use App\Domain\Appointments\Enums\ActivityType;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Enums\LocationType;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceDocument;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScheduleAppointment
{
    private const FIELDS = [
        'client_id', 'service_id', 'responsible_user_id', 'starts_at', 'ends_at',
        'service_details', 'location_type', 'location', 'notes',
    ];

    public function __construct(private EnsureResponsibleIsAvailable $ensureResponsibleIsAvailable) {}

    /**
     * @param  array{
     *     client_id: int,
     *     service_id: int,
     *     responsible_user_id: int,
     *     starts_at: CarbonInterface,
     *     ends_at: CarbonInterface,
     *     service_details?: ?string,
     *     location_type?: LocationType|string|null,
     *     location?: ?string,
     *     notes?: ?string,
     * }  $attributes  times already converted to UTC
     */
    public function handle(User $author, array $attributes): Appointment
    {
        $service = Service::query()->with('documents')->findOrFail($attributes['service_id']);
        $responsible = User::query()->findOrFail($attributes['responsible_user_id']);

        $this->ensureServiceCanBeUsed($service, $attributes['service_details'] ?? null);

        return DB::transaction(function () use ($author, $attributes, $service, $responsible): Appointment {
            $this->ensureResponsibleIsAvailable->handle($responsible, $attributes['starts_at'], $attributes['ends_at']);

            $appointment = Appointment::query()->create([
                ...Arr::only($attributes, self::FIELDS),
                'status' => AppointmentStatus::Scheduled,
                'created_by' => $author->id,
            ]);

            // Copied, so later edits to the service do not change booked appointments.
            $appointment->documents()->createMany(
                $service->documents->map(fn (ServiceDocument $document): array => [
                    'name' => $document->name,
                    'sort_order' => $document->sort_order,
                ]),
            );

            $appointment->recordActivity(ActivityType::Created, $author, new: [
                'starts_at' => $appointment->starts_at->toIso8601String(),
                'ends_at' => $appointment->ends_at->toIso8601String(),
                'responsible_user_id' => $responsible->id,
                'service_id' => $service->id,
            ]);

            return $appointment;
        });
    }

    private function ensureServiceCanBeUsed(Service $service, ?string $details): void
    {
        if (! $service->active) {
            throw ValidationException::withMessages([
                'service_id' => __('appointments.errors.inactive_service'),
            ]);
        }

        if ($service->requires_details && blank($details)) {
            throw ValidationException::withMessages([
                'service_details' => __('appointments.errors.details_required', ['service' => $service->name]),
            ]);
        }
    }
}
