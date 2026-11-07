<?php

declare(strict_types=1);

namespace App\Domain\Clients\Queries;

use App\Models\Appointment;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ClientHistory
{
    /**
     * @return Collection<int, Appointment>
     */
    public static function upcoming(Client $client): Collection
    {
        return self::base($client)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return Collection<int, Appointment>
     */
    public static function past(Client $client): Collection
    {
        return self::base($client)
            ->where('starts_at', '<', now())
            ->orderByDesc('starts_at')
            ->get();
    }

    /**
     * @return Builder<Appointment>
     */
    private static function base(Client $client): Builder
    {
        return $client->appointments()
            ->with(['client', 'service.category', 'responsible', 'documents'])
            ->getQuery();
    }
}
