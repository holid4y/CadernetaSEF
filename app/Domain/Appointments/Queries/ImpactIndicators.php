<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Queries;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Clients\Enums\ClientType;
use App\Models\Appointment;
use App\Support\DisplayTimezone;
use Carbon\CarbonInterface;
use Closure;
use Collator;
use Illuminate\Support\Collection;

// The same numbers as the Impact tab of the app (mobile/js/queries.js).
// Person type, MEI, third sector, demands and responsibles leave cancelled
// appointments out; people served counts completed appointments only.
final class ImpactIndicators
{
    public const MEI_SERVICE = 'atendimento-mei';

    public const THIRD_SECTOR_CATEGORY = 'terceiro-setor';

    /**
     * @return array<string, mixed>
     */
    public static function between(CarbonInterface $from, CarbonInterface $to): array
    {
        $list = Appointment::query()
            ->with(['client:id,type', 'service:id,slug,name,service_category_id', 'service.category:id,slug', 'responsible:id,name'])
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->get();

        $active = $list->reject(fn (Appointment $appointment): bool => $appointment->status === AppointmentStatus::Cancelled);
        $completed = $list->filter(fn (Appointment $appointment): bool => $appointment->status === AppointmentStatus::Completed);
        $missed = $list->filter(fn (Appointment $appointment): bool => $appointment->status === AppointmentStatus::NoShow)->count();

        return [
            'total' => $list->count(),
            'status' => collect(AppointmentStatus::cases())
                ->mapWithKeys(fn (AppointmentStatus $status): array => [$status->value => $list->filter(fn (Appointment $appointment): bool => $appointment->status === $status)->count()])
                ->all(),
            'attendance_rate' => $completed->count() + $missed === 0 ? null : $completed->count() / ($completed->count() + $missed),
            'unique_clients' => $completed->pluck('client_id')->unique()->count(),
            'individuals' => $active->filter(fn (Appointment $appointment): bool => $appointment->client->type === ClientType::Individual)->count(),
            'organizations' => $active->filter(fn (Appointment $appointment): bool => $appointment->client->type === ClientType::Organization)->count(),
            'mei' => $active->filter(fn (Appointment $appointment): bool => $appointment->service->slug === self::MEI_SERVICE)->count(),
            'third_sector' => $active->filter(fn (Appointment $appointment): bool => $appointment->service->category->slug === self::THIRD_SECTOR_CATEGORY)->count(),
            'demands' => self::ranking($active, fn (Appointment $appointment): string => $appointment->service->name),
            'by_responsible' => self::ranking($active, fn (Appointment $appointment): string => $appointment->responsible->name),
            'people_by_month' => self::peopleByMonth($completed, $from, $to),
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @param  Closure(Appointment): string  $name
     * @return list<array{name: string, count: int}>
     */
    private static function ranking(Collection $appointments, Closure $name): array
    {
        $collator = new Collator('pt_BR');

        return $appointments
            ->countBy($name)
            ->map(fn (int $count, string $label): array => ['name' => $label, 'count' => $count])
            ->sort(fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $collator->compare($a['name'], $b['name']))
            ->values()
            ->all();
    }

    /**
     * Different people served in each calendar month the period touches.
     *
     * @param  Collection<int, Appointment>  $completed
     * @return list<array{key: string, label: string, count: int}>
     */
    private static function peopleByMonth(Collection $completed, CarbonInterface $from, CarbonInterface $to): array
    {
        $months = [];
        $last = DisplayTimezone::toLocal($to)->subSecond()->startOfMonth();

        for ($month = DisplayTimezone::toLocal($from)->startOfMonth(); $month->lessThanOrEqualTo($last); $month->addMonth()) {
            $key = $month->format('Y-m');
            $months[] = [
                'key' => $key,
                'label' => rtrim($month->translatedFormat('M'), '.'),
                'count' => $completed
                    ->filter(fn (Appointment $appointment): bool => DisplayTimezone::toLocal($appointment->starts_at)->format('Y-m') === $key)
                    ->pluck('client_id')
                    ->unique()
                    ->count(),
            ];
        }

        return $months;
    }
}
