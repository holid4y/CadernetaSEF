<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Domain\Appointments\Enums\ActivityType;
use App\Models\Appointment;
use App\Models\AppointmentActivity;
use App\Models\AppointmentDocument;
use App\Models\ExternalForm;
use App\Support\FormLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canWrite = $request->user()?->canWrite() === true;

        return [
            'id' => $this->id,
            'clientId' => $this->client_id,
            'serviceSlug' => $this->service->slug,
            'serviceDetails' => $this->service_details,
            'responsibleId' => $this->responsible_user_id,
            'start' => $this->starts_at->getTimestampMs(),
            'end' => $this->ends_at->getTimestampMs(),
            'status' => $this->status->value,
            'locationType' => $this->location_type?->value ?? 'on_campus',
            'location' => $this->location,
            'notes' => $canWrite ? $this->notes : null,
            'createdBy' => $this->created_by,
            'createdAt' => $this->created_at?->getTimestampMs(),
            'updatedAt' => $this->updated_at?->getTimestampMs(),
            'reminderSentAt' => $this->reminder_sent_at?->getTimestampMs(),
            'documents' => $this->documents->map(fn (AppointmentDocument $document): array => self::document($document))->all(),
            'activities' => $this->activities->sortBy([['created_at', 'asc'], ['id', 'asc']])
                ->map(fn (AppointmentActivity $activity): array => self::activity($activity))->values()->all(),
            'integrationId' => $this->integration_id,
            'formsEnabled' => (bool) config('services.forms.enabled'),
            'formCreateUrl' => $canWrite ? FormLinks::create($this->resource) : null,
            'forms' => $this->externalForms->map(fn (ExternalForm $form): array => [
                'title' => $form->title,
                'status' => $form->status,
                'url' => FormLinks::open($form),
            ])->all(),
        ];
    }

    /**
     * @return array{id: int, name: string, receivedAt: ?int}
     */
    public static function document(AppointmentDocument $document): array
    {
        return [
            'id' => $document->id,
            'name' => $document->name,
            'receivedAt' => $document->received_at?->getTimestampMs(),
        ];
    }

    private static function activity(AppointmentActivity $activity): array
    {
        $old = $activity->old_values ?? [];
        $new = $activity->new_values ?? [];

        [$before, $after] = match ($activity->event_type) {
            ActivityType::Created => [null, null],
            ActivityType::Rescheduled => [self::period($old), self::period($new)],
            ActivityType::ResponsibleChanged => [['userId' => $old['responsible_user_id'] ?? null], ['userId' => $new['responsible_user_id'] ?? null]],
            ActivityType::StatusChanged, ActivityType::Cancelled => [['status' => $old['status'] ?? null], ['status' => $new['status'] ?? null]],
        };

        return [
            'id' => $activity->id,
            'type' => $activity->event_type->value,
            'userId' => $activity->user_id,
            'at' => $activity->created_at->getTimestampMs(),
            'old' => $before,
            'new' => $after,
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return array{start: int, end: int}
     */
    private static function period(array $values): array
    {
        return [
            'start' => Carbon::parse($values['starts_at'])->getTimestampMs(),
            'end' => Carbon::parse($values['ends_at'])->getTimestampMs(),
        ];
    }
}
