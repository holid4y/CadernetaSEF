<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Actions;

use App\Models\AppointmentDocument;
use Illuminate\Validation\ValidationException;

final class MarkDocumentReceived
{
    public function handle(AppointmentDocument $document, bool $received): AppointmentDocument
    {
        if ($document->isReceived() === $received) {
            return $document;
        }

        if (! $document->loadMissing('appointment')->appointment->documentsAreDue()) {
            throw ValidationException::withMessages(['documents' => __('appointments.errors.documents_on_the_day')]);
        }

        $document->update(['received_at' => $received ? now() : null]);

        return $document;
    }
}
