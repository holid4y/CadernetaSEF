<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The login screens ask while the e-mail is typed. Only an account still
// waiting for its first password gets a yes: one that has a password and an
// address that does not exist get the same no.
class AccountStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        return response()->json([
            'awaiting_password' => User::query()->awaitingPassword()->where('email', mb_strtolower($data['email']))->exists(),
        ]);
    }
}
