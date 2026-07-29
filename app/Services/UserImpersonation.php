<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserImpersonation
{
    public const SESSION_KEY = 'admin_impersonator_id';

    public const SESSION_NAME_KEY = 'admin_impersonator_name';

    public function start(Request $request, User $targetUser): void
    {
        $admin = $request->user();

        abort_unless($admin?->isAdmin(), 403);
        abort_if($request->session()->has(self::SESSION_KEY), 403);
        abort_if($admin->is($targetUser), 422);

        $request->session()->put(self::SESSION_KEY, $admin->getKey());
        $request->session()->put(self::SESSION_NAME_KEY, $admin->name);

        Auth::login($targetUser);

        $request->session()->regenerate();
    }

    public function stop(Request $request): User
    {
        $impersonatorId = $request->session()->get(self::SESSION_KEY);

        abort_unless($impersonatorId, 403);

        $impersonator = User::query()->find($impersonatorId);

        abort_unless($impersonator?->isAdmin(), 403);

        $request->session()->forget([
            self::SESSION_KEY,
            self::SESSION_NAME_KEY,
        ]);

        Auth::login($impersonator);

        $request->session()->regenerate();

        return $impersonator;
    }
}
