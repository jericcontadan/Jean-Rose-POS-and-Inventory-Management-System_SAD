{{-- Forgot password, step 1 (mockup: docs/design/02-forgot-reset-password.png, left card). Email only (SMS is planned, not built). --}}
@extends('layouts.guest')

@section('title', 'Forgot password')

@section('content')
<div class="jr-reset">
    <div class="jr-reset-brand">
        <span class="jr-reset-brand-name">Jean Rose</span>
        <span class="jr-reset-brand-tag">BEAUTY PRODUCTS TRADING</span>
    </div>

    <form method="POST" action="{{ route('password.email') }}" class="jr-login-card jr-reset-card" novalidate>
        @csrf

        <span class="jr-reset-step">Step 1 of 2</span>

        <div>
            <h1 class="jr-login-title">Forgot password</h1>
            <p class="jr-login-lead">Enter the email of your account and we will send you a reset link.</p>
        </div>

        {{-- The same message for every email (registered or not). --}}
        @if (session('status'))
            <div class="alert alert-success mb-0" role="status">{{ session('status') }}</div>
        @endif

        <div>
            <label for="email" class="jr-login-label">Email *</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}"
                   class="form-control jr-login-input @error('email') is-invalid @enderror"
                   placeholder="Enter your email" autocomplete="email" autocapitalize="none"
                   spellcheck="false" required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        {{-- How to send the reset (mockup). Only Gmail works; SMS is shown but disabled until it is built. --}}
        <fieldset class="jr-reset-by">
            <legend class="jr-login-label">Send the reset by</legend>
            <div class="jr-reset-options">
                <label for="by-email" class="jr-reset-option is-selected">
                    <input id="by-email" type="radio" name="by" value="email" checked>
                    <span class="jr-reset-option-text">
                        <span class="jr-reset-option-title">Email (Gmail)</span>
                        <span class="jr-reset-option-sub">a one-time link</span>
                    </span>
                </label>
                <label for="by-sms" class="jr-reset-option is-disabled" aria-describedby="sms-note">
                    <input id="by-sms" type="radio" name="by" value="sms" disabled>
                    <span class="jr-reset-option-text">
                        <span class="jr-reset-option-title">SMS</span>
                        <span class="jr-reset-option-sub">a 6-digit code to the phone</span>
                    </span>
                </label>
            </div>
            <p id="sms-note" class="jr-reset-note text-start mt-2">SMS reset coming soon.</p>
            @error('by')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </fieldset>

        <button type="submit" class="btn btn-primary jr-login-btn">Send reset link</button>

        <a href="{{ route('login') }}" class="jr-login-link">Back to log in</a>

        <p class="jr-reset-note">The same message is shown whether or not the account exists.</p>
    </form>
</div>
@endsection
