<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $production = $this->app->isProduction();

        Model::shouldBeStrict(! $production);

        DB::prohibitDestructiveCommands($production);

        URL::forceHttps($production);

        if (filled(config('mail.always_to'))) {
            Mail::alwaysTo(config('mail.always_to'));
        }

        Password::defaults(function () use ($production): Password {
            $rule = Password::min(10)->letters()->numbers();

            return $production ? $rule->uncompromised() : $rule;
        });
    }
}
