{{--
    Friendly 403 page (Laravel shows it for every "Forbidden", for example a cashier opening Sales Records
    or the owner opening the POS). Same pink look as the Forgot Password page (.jr-reset / .jr-login-card).
    The button goes back to the user's home page: Dashboard for the owner, POS for the cashier (UserAccount::homeRoute()).
--}}
@extends('layouts.guest')

@section('title', 'No access')

@php
    $user = auth()->user();
    $homeUrl = $user ? route($user->homeRoute()) : route('login');
    $homeText = $user ? ($user->homeRoute() === 'dashboard' ? 'Go to Dashboard' : 'Go to POS') : 'Go to Log in';
@endphp

@section('content')
<div class="jr-reset jr-error-page">
    <div class="jr-reset-brand" aria-label="Jean Rose Beauty Products Trading">
        <span class="jr-reset-brand-name">Jean Rose</span>
        <span class="jr-reset-brand-tag">BEAUTY PRODUCTS TRADING</span>
    </div>

    <main class="jr-login-card jr-error-card">
        <div class="jr-error-icon" aria-hidden="true">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg>
        </div>
        <div>
            <h1 class="jr-login-title">You can't open this page</h1>
            <p class="jr-login-lead">Your account does not have access to this page. If you think this is a mistake, please ask the owner.</p>
        </div>
        <a href="{{ $homeUrl }}" class="btn btn-primary jr-login-btn d-flex align-items-center justify-content-center">{{ $homeText }}</a>
        <p class="jr-reset-note">Error 403 · No access</p>
    </main>
</div>
@endsection
