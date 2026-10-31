<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Google\GoogleAuthorization;
use App\Jobs\PullGoogleEvents;
use App\Models\GoogleAccount;
use Illuminate\Console\Command;

final class PullGoogleCalendars extends Command
{
    protected $signature = 'google:pull';

    protected $description = 'Read the Google calendars chosen by the team into the agenda';

    public function handle(): int
    {
        if (! GoogleAuthorization::configured()) {
            return self::SUCCESS;
        }

        GoogleAccount::query()
            ->working()
            ->whereNotNull('source_calendar_id')
            ->pluck('id')
            ->each(fn (int $id) => PullGoogleEvents::dispatch($id));

        return self::SUCCESS;
    }
}
