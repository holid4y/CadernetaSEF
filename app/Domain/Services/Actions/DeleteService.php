<?php

declare(strict_types=1);

namespace App\Domain\Services\Actions;

use App\Models\Appointment;
use App\Models\Service;
use App\Support\DisplayTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes a service out of the catalog. The row stays, so appointments that
 * used it keep their history; one still to happen blocks the removal.
 */
final class DeleteService
{
    public function handle(Service $service): void
    {
        DB::transaction(function () use ($service): void {
            // Locked, so nobody books it while the appointments are checked.
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            $upcoming = Appointment::query()
                ->where('service_id', $locked->id)
                ->blocking()
                ->where('ends_at', '>', now())
                ->orderBy('starts_at')
                ->pluck('starts_at');

            if ($upcoming->isNotEmpty()) {
                throw ValidationException::withMessages(['service' => trans_choice('services.errors.has_upcoming', $upcoming->count(), [
                    'count' => $upcoming->count(),
                    'date' => DisplayTimezone::toLocal($upcoming->first())->format('d/m'),
                ])]);
            }

            $locked->delete();
        });
    }
}
