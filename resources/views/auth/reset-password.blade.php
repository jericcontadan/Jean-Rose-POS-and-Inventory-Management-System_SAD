{{-- Reset password, step 2 (mockup: docs/design/02-forgot-reset-password.png, right card). Opened from the email link. --}}
@extends('layouts.guest')

@section('title', 'Reset password')

@section('content')
<div class="jr-reset">
    <div class="jr-reset-brand">
        <span class="jr-reset-brand-name">Jean Rose</span>
        <span class="jr-reset-brand-tag">BEAUTY PRODUCTS TRADING</span>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="jr-login-card jr-reset-card" novalidate>
        @csrf
        {{-- The plain token from the link; the server compares it with the hash in password_reset_tokens. --}}
        <input type="hidden" name="token" value="{{ $token }}">

        <span class="jr-reset-step">Step 2 of 2</span>

        <div>
            <h1 class="jr-login-title">Reset password</h1>
            <p class="jr-login-lead">Choose a new password with at least 8 characters.</p>
        </div>

        <div>
            <label for="email" class="jr-login-label">Email *</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}"
                   class="form-control jr-login-input @error('email') is-invalid @enderror"
                   autocomplete="email" autocapitalize="none" spellcheck="false" required>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="password" class="jr-login-label">New password *</label>
            <div class="jr-password">
                <input id="password" name="password" type="password"
                       class="form-control jr-login-input @error('password') is-invalid @enderror"
                       placeholder="At least 8 characters" autocomplete="new-password" minlength="8" required autofocus>
                <button type="button" class="jr-password-toggle" data-toggle-for="password"
                        aria-label="Show password" aria-pressed="false">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div>
            <label for="password_confirmation" class="jr-login-label">Confirm password *</label>
            <div class="jr-password">
                <input id="password_confirmation" name="password_confirmation" type="password"
                       class="form-control jr-login-input" placeholder="Type it again"
                       autocomplete="new-password" required>
                <button type="button" class="jr-password-toggle" data-toggle-for="password_confirmation"
                        aria-label="Show password" aria-pressed="false">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary jr-login-btn">Save password</button>

        <a href="{{ route('password.request') }}" class="jr-login-link">Send a new link</a>

        <p class="jr-reset-note">Each link works once and expires after 60 minutes.</p>
    </form>
</div>
@endsection

@push('scripts')
<script>
    // Show / hide each password box when its eye button is tapped.
    document.querySelectorAll('[data-toggle-for]').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = document.getElementById(button.dataset.toggleFor);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });
</script>
@endpush
