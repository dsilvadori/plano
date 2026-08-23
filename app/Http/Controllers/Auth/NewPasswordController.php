<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->boolean('first_access') && $this->hasCompletedFirstAccessCookie($request)) {
            $request->session()->put('url.intended', route('courses.mine'));

            return redirect()->route('login')
                ->with('status', 'Sua senha já foi criada. Entre para acessar seu curso.');
        }

        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $isFirstAccess = $request->boolean('first_access');
        $resetUser = null;

        $status = Password::broker($isFirstAccess ? 'first_access' : null)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, &$resetUser) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                $resetUser = $user;
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __($status)]);
        }

        if (! $isFirstAccess || ! $resetUser instanceof User) {
            return redirect()->route('login')->with('status', __($status));
        }

        Auth::login($resetUser);
        $request->session()->regenerate();

        return redirect($this->firstAccessRedirectUrl($resetUser))
            ->withCookie(cookie(
                $this->firstAccessCookieName(),
                $this->firstAccessCookieValue($resetUser->email),
                60 * 24 * 365,
                null,
                null,
                $request->isSecure(),
                true,
                false,
                'Lax',
            ));
    }

    protected function hasCompletedFirstAccessCookie(Request $request): bool
    {
        $email = $request->query('email');

        return is_string($email)
            && $email !== ''
            && hash_equals(
                $this->firstAccessCookieValue($email),
                (string) $request->cookie($this->firstAccessCookieName(), ''),
            );
    }

    protected function firstAccessCookieName(): string
    {
        return 'vc_first_access_completed';
    }

    protected function firstAccessCookieValue(string $email): string
    {
        return hash_hmac('sha256', Str::lower($email), (string) config('app.key'));
    }

    protected function firstAccessRedirectUrl(User $user): string
    {
        $course = $user->availableCoursesQuery()
            ->where('status', 'published')
            ->orderBy('name')
            ->first();

        return $course instanceof Course
            ? route('courses.show', ['course' => $course->slug])
            : route('courses.mine');
    }
}
