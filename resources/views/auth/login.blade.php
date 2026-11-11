{{-- Login screen (mockup: docs/design/01-login.png). Left: logo panel. Right: login card. --}}
@extends('layouts.guest')

@section('title', 'Log in')

@section('content')
<div class="jr-login">
    <section class="jr-login-brand">
        <img src="{{ asset('images/logo.png') }}" class="jr-login-logo"
             alt="Jean Rose Beauty Products Trading. Facebook: Jean Rose Alverio. Phone: 09454920107">
        <div class="jr-login-subtitle">POS and Inventory System</div>
    </section>

    <section class="jr-login-side">
        <form method="POST" action="{{ route('login.store') }}" class="jr-login-card" novalidate>
            @csrf

            <div>
                <h1 class="jr-login-title">Welcome back</h1>
                <p class="jr-login-lead">Log in to start selling.</p>
            </div>

            {{-- One error box for all login errors (wrong login, inactive, too many tries, empty fields). --}}
            @if ($errors->any())
                <div class="alert alert-danger mb-0" role="alert">{{ $errors->first() }}</div>
            @endif

            <div>
                <label for="username" class="jr-login-label">Username</label>
                <input id="username" name="username" type="text" value="{{ old('username') }}"
                       class="form-control jr-login-input" placeholder="Enter your username"
                       autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
            </div>

            <div>
                <label for="password" class="jr-login-label">Password</label>
                <div class="jr-password">
                    <input id="password" name="password" type="password"
                           class="form-control jr-login-input" placeholder="Enter your password"
                           autocomplete="current-password" required>
                    {{-- Tap to show or hide the password. --}}
                    <button type="button" class="jr-password-toggle" id="togglePassword"
                            aria-label="Show password" aria-pressed="false">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary jr-login-btn">Log in</button>

            {{-- Shown once Step 11 (Forgot and Reset Password) adds this route. --}}
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="jr-login-link">Forgot password?</a>
            @endif
        </form>
    </section>
</div>
@endsection

@push('scripts')
<script>
    // Show / hide password when the eye button is tapped.
    document.getElementById('togglePassword').addEventListener('click', function () {
        const input = document.getElementById('password');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        this.setAttribute('aria-pressed', show ? 'true' : 'false');
        this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
</script>
@endpush
