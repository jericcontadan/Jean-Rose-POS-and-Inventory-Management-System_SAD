<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Not logged in -> login page. Already logged in and opening /login -> their home page
        // (Dashboard for the owner, POS for the cashier).
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo(fn (Request $request) => route($request->user()->homeRoute()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 419 "Page Expired": the session ran out, so the form's CSRF token is no longer valid.
        // Instead of Laravel's plain page, send the user to the login page with a clear message.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null; // every other error: normal handling
            }

            // Log out with an expired session: just finish logging out and show the login page.
            if ($request->is('logout')) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login');
            }

            // Still logged in (only the open page was old, for example a second tab): go back and try again.
            if (Auth::check()) {
                return back()->with('error', 'This page expired. Please try again.');
            }

            return redirect()->route('login')->with('error', 'Your session expired. Please log in again.');
        });
    })->create();
