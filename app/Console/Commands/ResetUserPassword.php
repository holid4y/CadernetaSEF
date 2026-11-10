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
    protected $signature = 'users:password
        {email : Login e-mail}
        {--clear : Leave the account without a password, to be created from the login page}';

    protected $description = 'Set a new password for a staff account and sign it out everywhere';

    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->components->error('No account with this e-mail.');

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            $this->save($user, null);
            $this->components->info("{$user->email} has no password now; other sessions were closed.");

            return self::SUCCESS;
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

        $this->save($user, $validator->validated()['password']);
        $this->components->info("New password saved for {$user->email}; other sessions were closed.");

        return self::SUCCESS;
    }

    private function save(User $user, ?string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();
            $user->signOutEverywhere();
        });
    }
}
