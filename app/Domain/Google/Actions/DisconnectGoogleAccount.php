<?php

declare(strict_types=1);

namespace App\Domain\Google\Actions;

use App\Domain\Google\GoogleAuthorization;
use App\Domain\Google\GoogleCalendar;
use App\Models\GoogleAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DisconnectGoogleAccount
{
    public function __construct(
        private GoogleCalendar $calendar,
        private GoogleAuthorization $authorization,
    ) {}

    public function handle(GoogleAccount $account): void
    {
        // Copies that nobody updates any more would only mislead.
        try {
            $this->calendar->deleteCalendar($account);
        } catch (Throwable $error) {
            Log::warning('The Caderneta SEF calendar could not be removed from Google.', [
                'google_account_id' => $account->id,
                'error' => $error::class,
            ]);
        }

        $this->authorization->revoke($account->refresh_token);
        $account->delete();
    }
}
