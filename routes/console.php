<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('appointments:send-reminders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('google:pull')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:clear-resets')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();
