<?php

declare(strict_types=1);

namespace App\Domain\Clients\Enums;

enum ClientType: string
{
    case Individual = 'individual';
    case Organization = 'organization';
    case Ngo = 'ngo';

    public function label(): string
    {
        return __("clients.type.{$this->value}");
    }

    // Companies and NGOs are registered by CNPJ and corporate name.
    public function isLegalEntity(): bool
    {
        return $this !== self::Individual;
    }
}
