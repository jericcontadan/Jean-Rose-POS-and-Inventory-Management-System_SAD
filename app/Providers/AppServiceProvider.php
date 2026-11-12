<?php

namespace App\Providers;

use App\Models\AlertNotification;
use App\Models\Sale;
use App\Models\UserAccount;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Page links (Sales Records) use Bootstrap 5 markup, since the layout uses Bootstrap.
        Paginator::useBootstrapFive();

        // The only access rule in the app: is this user the OWNER?
        // Used by @can('owner') in views and by can:owner on owner-only routes.
        Gate::define('owner', fn (UserAccount $user) => $user->role === 'OWNER');

        // A receipt: the owner may open any receipt, a cashier only the sales they made.
        Gate::define('view-receipt', fn (UserAccount $user, Sale $sale) => $user->role === 'OWNER' || (int) $sale->user_id === (int) $user->user_id);

        // Give the header the number of open alerts, so the Blade view runs no query itself.
        View::composer('partials.header', function ($view) {
            $view->with('openAlertCount', Auth::check()
                ? AlertNotification::whereNull('resolved_at')->count()
                : 0);
        });
    }
}
