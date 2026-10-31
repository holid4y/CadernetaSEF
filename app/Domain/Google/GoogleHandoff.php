<?php

declare(strict_types=1);

namespace App\Domain\Google;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// Google does not let people sign in inside the app, so the app opens the
// phone's browser with a link that works once, for a few minutes. Only a
// hash of it is kept.
final class GoogleHandoff
{
    public const MINUTES = 5;

    public static function issue(User $user): string
    {
        $token = Str::random(64);
        Cache::put(self::key($token), $user->id, now()->addMinutes(self::MINUTES));

        return $token;
    }

    public static function redeem(string $token): ?User
    {
        $userId = Cache::pull(self::key($token));
        $user = $userId === null ? null : User::query()->find($userId);

        return $user?->canWrite() ? $user : null;
    }

    private static function key(string $token): string
    {
        return 'google-handoff:'.hash('sha256', $token);
    }
}
