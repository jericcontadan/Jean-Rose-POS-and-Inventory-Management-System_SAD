<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\UserAccount;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

// Forgot and Reset Password by Gmail link (screen 2, FR-03, T19).
// Laravel's password broker does the token work: it stores only a HASH of the token in
// password_reset_tokens, the link expires after 60 minutes (config/auth.php), the row is deleted
// after a successful reset (works once), and a new request replaces the old row.
class PasswordResetController extends Controller
{
    // The same message for every email, so nobody can check which emails are registered.
    public const SENT_MESSAGE = 'If that email is registered, we sent a reset link.';
    public const INVALID_LINK = 'This reset link is invalid or has expired. Please request a new one.';

    // At most 3 reset requests per email per 15 minutes.
    private const MAX_REQUESTS = 3;
    private const WINDOW_SECONDS = 15 * 60;

    // GET /forgot-password: step 1, enter your email.
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    // POST /forgot-password: send the reset link (if the email belongs to an active account).
    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $email = Str::lower($request->input('email'));
        $key = 'password-reset:'.$email;

        // Counted for every email, registered or not, so this message gives nothing away.
        if (RateLimiter::tooManyAttempts($key, self::MAX_REQUESTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => "Too many reset requests. Please try again in {$minutes} minutes."]);
        }
        RateLimiter::hit($key, self::WINDOW_SECONDS);

        // Only active accounts get a link. The broker calls UserAccount::sendPasswordResetNotification(),
        // which sends our pink PasswordResetMail. We ignore the result on purpose (same message always).
        try {
            Password::sendResetLink(['email' => $email, 'is_active' => 1]);
        } catch (TransportExceptionInterface $e) {
            // Gmail SMTP failed. The broker already saved a token, but nobody got the link: delete it.
            // Log the error (never the token) and still show the same message, so a failure reveals nothing.
            $this->deleteUnsentToken($email);
            Log::error('Password reset email could not be sent.', [
                'error' => get_class($e).': '.$e->getMessage(),
            ]);
        }

        return back()->with('status', self::SENT_MESSAGE);
    }

    // Remove the token saved for an email whose reset link was never delivered.
    private function deleteUnsentToken(string $email): void
    {
        $user = Password::getUser(['email' => $email, 'is_active' => 1]);

        if ($user) {
            Password::deleteToken($user);
        }
    }

    // GET /reset-password/{token}?email=...: step 2, the page the email link opens.
    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    // POST /reset-password: check the token and save the new password.
    public function update(ResetPasswordRequest $request, AuditService $audit): RedirectResponse
    {
        $credentials = [
            'email' => Str::lower($request->input('email')),
            'is_active' => 1,
            'token' => $request->input('token'),
            'password' => $request->input('password'),
        ];

        // One transaction: new password + audit row + token delete all happen, or none of them.
        $status = DB::transaction(fn () => Password::reset(
            $credentials,
            function (UserAccount $user, string $password) use ($audit) {
                $user->password_hash = Hash::make($password); // our column is password_hash
                $user->save();

                $audit->log($user, 'PASSWORD_RESET', 'SALES', null, 'Password reset by email link');
            }
        ));

        if ($status !== Password::PASSWORD_RESET) {
            // Wrong, expired or already used token (or unknown email): one message for all.
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => self::INVALID_LINK]);
        }

        return redirect()->route('login')->with('success', 'Your password was changed. Please log in.');
    }
}
