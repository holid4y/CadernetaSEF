<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Models\AppointmentDocument;
use App\Models\ExternalForm;
use App\Support\FormLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the forms platform gets about an appointment, so its forms come
 * filled in. The team's notes stay in the Caderneta.
 *
 * @mixin Appointment
 */
class FormAppointmentResource extends JsonResource
{
    public const RELATIONS = ['client', 'service.category', 'responsible', 'documents', 'externalForms'];

    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'appointment_id' => $this->integration_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'location_type' => $this->location_type?->value,
            'location' => $this->location,
            'service' => [
                'slug' => $this->service->slug,
                'name' => $this->service->name,
                'category' => $this->service->category->name,
                'details' => $this->service_details,
            ],
            'responsible' => ['name' => $this->responsible->name],
            'client' => [
                'type' => $this->client->type->value,
                'name' => $this->client->name,
                'trade_name' => $this->client->trade_name,
                'document' => $this->client->document,
                'email' => $this->client->email,
                'phone' => $this->client->phone,
            ],
            'documents' => $this->documents->map(fn (AppointmentDocument $document): array => [
                'name' => $document->name,
                'received' => $document->isReceived(),
            ])->all(),
            'forms' => $this->externalForms->map(fn (ExternalForm $form): array => [
                'form_id' => $form->external_id,
                'title' => $form->title,
                'status' => $form->status,
                'url' => FormLinks::open($form),
            ])->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
