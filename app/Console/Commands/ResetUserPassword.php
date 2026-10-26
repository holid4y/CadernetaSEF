<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

// For servers without e-mail, and for rotating a password that leaked:
// the old one stops working on every device.
final class ResetUserPassword extends Command
{
    protected $signature = 'users:password {email : Login e-mail}';

    protected $description = 'Set a new password for a staff account and sign it out everywhere';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->components->error('No account with this e-mail.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'password' => (string) $this->secret('New password (at least 10 characters, letters and numbers)'),
            'password_confirmation' => (string) $this->secret('New password again'),
        ], ['password' => ['required', 'confirmed', Password::defaults()]]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $validator): void {
            $user->forceFill([
                'password' => $validator->validated()['password'],
                'remember_token' => Str::random(60),
            ])->save();
            $user->signOutEverywhere();
        });

        $this->components->info("New password saved for {$user->email}; other sessions were closed.");

        return self::SUCCESS;
    }
}
