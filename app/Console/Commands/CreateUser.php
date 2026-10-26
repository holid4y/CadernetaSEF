<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Users\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

// There is no public sign-up: accounts, including the first administrator,
// are created on the server. The password is typed without echo, so it
// never lands in the shell history.
final class CreateUser extends Command
{
    protected $signature = 'users:create
        {email : Login e-mail}
        {name : Name shown in the agenda}
        {--role=member : admin, member or viewer}';

    protected $description = 'Create a staff account, asking for its password';

    public function handle(): int
    {
        $password = (string) $this->secret('Password (at least 10 characters, letters and numbers)');
        $confirmation = (string) $this->secret('Password again');

        $validator = Validator::make([
            'email' => mb_strtolower(trim((string) $this->argument('email'))),
            'name' => trim((string) $this->argument('name')),
            'role' => $this->option('role'),
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();

        $user = User::query()->create([
            'email' => $data['email'],
            'name' => $data['name'],
            'role' => $data['role'],
            'password' => $data['password'],
            'active' => true,
        ]);

        $this->components->info("Account {$user->email} created as {$user->role->label()}.");

        return self::SUCCESS;
    }
}
