<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Google\Actions\DisconnectGoogleAccount;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Accounts are never deleted: the history keeps pointing to them.
final class DeactivateUser extends Command
{
    protected $signature = 'users:deactivate
        {email : Login e-mail}
        {--undo : Give the access back}';

    protected $description = 'Block a staff account, ending its web sessions, mobile tokens and Google connection';

    public function handle(DisconnectGoogleAccount $disconnectGoogle): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->components->error('No account with this e-mail.');

            return self::FAILURE;
        }

        if ($this->option('undo')) {
            $user->update(['active' => true]);
            $this->components->info("{$user->email} can sign in again.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user): void {
            $user->update(['active' => false]);
            $user->signOutEverywhere();
        });

        // A blocked account must not keep reading or writing a Google calendar.
        $google = GoogleAccount::query()->where('user_id', $user->id)->first();

        if ($google !== null) {
            $disconnectGoogle->handle($google);
        }

        $this->components->info("{$user->email} was blocked and signed out everywhere.");

        return self::SUCCESS;
    }
}
