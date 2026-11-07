<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\DisplayTimezone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgendaEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->client->name,
            'start' => DisplayTimezone::toLocal($this->starts_at)->format('Y-m-d\TH:i:s'),
            'end' => DisplayTimezone::toLocal($this->ends_at)->format('Y-m-d\TH:i:s'),
            'url' => route('appointments.show', $this->resource),
            'classNames' => ['agenda-event', "agenda-event--{$this->status->value}"],
            'editable' => $this->status->canBeRescheduled() && $request->user()->can('update', $this->resource),
            'extendedProps' => [
                'service' => $this->service->name,
                'responsible' => $this->responsible->name,
                'status' => $this->status->label(),
            ],
        ];
    }
}
