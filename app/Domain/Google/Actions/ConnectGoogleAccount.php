<?php

declare(strict_types=1);

namespace App\Domain\Google\Actions;

use App\Jobs\SyncGoogleAccount;
use App\Models\GoogleAccount;
use App\Models\User;

final class ConnectGoogleAccount
{
    public function __construct(private DisconnectGoogleAccount $disconnect) {}

    /**
     * @param  array{email: string, refresh_token: string, access_token: string, expires_in: int, scopes: string}  $grant
     */
    public function handle(User $user, array $grant): GoogleAccount
    {
        $account = GoogleAccount::query()->where('user_id', $user->id)->first();

        // Another Google account takes the place of the old one, which loses
        // its calendar. The same account keeps everything and gets new tokens.
        if ($account !== null && $account->email !== $grant['email']) {
            $this->disconnect->handle($account);
            $account = null;
        }

        $account ??= new GoogleAccount(['user_id' => $user->id]);
        $account->fill([
            'email' => $grant['email'],
            'refresh_token' => $grant['refresh_token'],
            'access_token' => $grant['access_token'],
            'access_token_expires_at' => now()->addSeconds($grant['expires_in']),
            'scopes' => $grant['scopes'],
            'failed_at' => null,
        ])->save();

        SyncGoogleAccount::dispatch($account->id);

        return $account;
    }
}
