<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Domain\Google\GoogleAuthorization;
use App\Domain\Google\GoogleCalendar;
use App\Models\GoogleAccount;
use App\Models\User;

final class GoogleStatus
{
    /**
     * @return array<string, mixed>
     */
    public static function for(User $user): array
    {
        $account = $user->canWrite() ? GoogleAccount::query()->where('user_id', $user->id)->first() : null;

        return [
            'available' => GoogleAuthorization::configured() && $user->canWrite(),
            'connected' => $account !== null,
            'email' => $account?->email,
            'needsReconnect' => $account?->failed_at !== null,
            'calendarId' => $account?->source_calendar_id,
            'calendarName' => $account?->source_calendar_name,
            'pulledAt' => $account?->pulled_at?->getTimestampMs(),
            'webUrl' => GoogleCalendar::WEB_URL,
        ];
    }
}
