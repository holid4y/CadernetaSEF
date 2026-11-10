<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
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

        // Whoever asks for the link chooses the Host header of the request, so
        // the address in the e-mail can not come from it.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim((string) config('app.url'), '/')
            .route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false));

        Password::defaults(function () use ($production): Password {
            $rule = Password::min(10)->letters()->numbers();

            return $production ? $rule->uncompromised() : $rule;
        });
    }
}
