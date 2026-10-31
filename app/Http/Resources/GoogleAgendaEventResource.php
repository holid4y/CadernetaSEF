<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Models\GoogleEvent;
use App\Support\DisplayTimezone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An event from someone's Google calendar still waiting to become an
 * appointment. It takes the shape of the appointments in the agenda.
 *
 * @mixin GoogleEvent
 */
class GoogleAgendaEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => "google-{$this->id}",
            'title' => $this->title,
            'start' => DisplayTimezone::toLocal($this->starts_at)->format('Y-m-d\TH:i:s'),
            'end' => DisplayTimezone::toLocal($this->ends_at)->format('Y-m-d\TH:i:s'),
            'url' => $request->user()->can('create', Appointment::class)
                ? route('appointments.create', ['google' => $this->id])
                : null,
            'classNames' => ['agenda-event', 'agenda-event--google'],
            'editable' => false,
            'extendedProps' => [
                'service' => __('google.from_google'),
                'responsible' => $this->account->user->name,
                'status' => __('google.pending'),
            ],
        ];
    }
}
