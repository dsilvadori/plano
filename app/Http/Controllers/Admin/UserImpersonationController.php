<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserImpersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserImpersonationController extends Controller
{
    public function start(Request $request, User $user, UserImpersonation $impersonation): RedirectResponse
    {
        $impersonation->start($request, $user);

        return redirect()
            ->route('dashboard')
            ->with('status', "Você está visualizando o sistema como {$user->name}.");
    }

    public function stop(Request $request, UserImpersonation $impersonation): RedirectResponse
    {
        $impersonation->stop($request);

        return redirect('/admin/users')
            ->with('status', 'Você voltou para sua conta de administrador.');
    }
}
