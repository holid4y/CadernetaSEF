<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Client;
use App\Rules\BrazilianDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The whole list travels to the phone, so CPF/CNPJ and notes only go
        // to those who edit records; viewers do not need them.
        $sensitive = $request->user()?->canWrite() === true;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'tradeName' => $this->trade_name,
            'document' => $sensitive && $this->document ? BrazilianDocument::characters($this->document) : null,
            'phone' => $this->phone,
            'email' => $this->email,
            'notes' => $sensitive ? $this->notes : null,
            'acceptsReminders' => $this->accepts_reminders,
            'consentAt' => $this->reminders_consented_at?->getTimestampMs(),
            'active' => $this->active && ! $this->trashed(),
            'createdAt' => $this->created_at?->getTimestampMs(),
            'updatedAt' => $this->updated_at?->getTimestampMs(),
        ];
    }
}
