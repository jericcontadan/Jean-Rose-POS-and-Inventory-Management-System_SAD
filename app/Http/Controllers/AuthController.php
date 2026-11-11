<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

// Log in and log out (screen 1, FR-01 / FR-02).
class AuthController extends Controller
{
    // One message for every failed login, so nobody can guess which usernames exist.
    public const GENERIC_ERROR = 'Wrong username or password.';

    // At most 5 tries per minute for the same username from the same device.
    private const MAX_TRIES = 5;
    private const LOCK_SECONDS = 60;

    // GET /login: show the login form.
    public function create(): View
    {
        return view('auth.login');
    }

    // POST /login: check username + password, only for active accounts.
    public function store(LoginRequest $request, AuditService $audit): RedirectResponse
    {
        $key = Str::lower($request->input('username')).'|'.$request->ip();

        // Too many tries? Stop here and tell the user how long to wait.
        if (RateLimiter::tooManyAttempts($key, self::MAX_TRIES)) {
            $seconds = RateLimiter::availableIn($key);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => "Too many login attempts. Please try again in {$seconds} seconds."]);
        }

        // is_active = 1 means inactive accounts can never log in.
        $credentials = [
            'username' => $request->input('username'),
            'password' => $request->input('password'),
            'is_active' => 1,
        ];

        if (! Auth::attempt($credentials)) {
            RateLimiter::hit($key, self::LOCK_SECONDS);

            return back()
                ->withInput($request->only('username')) // never send the password back
                ->withErrors(['username' => self::GENERIC_ERROR]);
        }

        // Success: reset the counter, get a new session id, write the audit row.
        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user = Auth::user();
        $audit->log($user, 'LOGIN', 'SALES', null, 'Logged in as '.$user->role);

        return redirect()->route('pos.index');
    }

    // POST /logout: end the session and go back to the login page.
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
