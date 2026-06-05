<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TestStudentPreviewController extends Controller
{
    public function enter(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $student = User::query()
            ->where('email', 'aluno@teste.com')
            ->where('role', 'student')
            ->firstOrFail();

        $request->session()->put('admin_preview_user_id', $request->user()->getKey());

        Auth::guard('web')->login($student);

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function exit(Request $request): RedirectResponse
    {
        $adminUserId = $request->session()->get('admin_preview_user_id');

        abort_unless($adminUserId, 403);

        $admin = User::query()
            ->whereKey($adminUserId)
            ->where('role', 'admin')
            ->firstOrFail();

        Auth::guard('web')->login($admin);

        $request->session()->forget('admin_preview_user_id');
        $request->session()->regenerate();

        return redirect('/admin');
    }
}
