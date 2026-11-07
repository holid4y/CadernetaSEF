<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Enums;

enum LocationType: string
{
    // Where the project sees people unless the booking says otherwise.
    public const DEFAULT_PLACE = 'Sala 105, Campus II';

    case OnCampus = 'on_campus';
    case External = 'external';
    case Remote = 'remote';

    public function label(): string
    {
        return __("appointments.location_type.{$this->value}");
    }
}
