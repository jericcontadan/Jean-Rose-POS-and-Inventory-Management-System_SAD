{{--
    Header bar (mockup: docs/design/05-inventory-owner.png).
    OWNER: wordmark, tabs (POS, Sales Records, Inventory, Audit Log), Alerts, Signed in as, Log out.
    CASHIER: same but with NO tabs (cashier only uses the POS).
    Guest: wordmark only.
    $openAlertCount comes from the view composer in AppServiceProvider.
    The menu only hides links; owner pages are also blocked on the server (can:owner).
--}}
<header class="jr-header navbar navbar-expand-lg">
    <a href="{{ url('/') }}" class="jr-wordmark" aria-label="Jean Rose home">
        <span class="jr-wordmark-name">Jean Rose</span>
        <span class="jr-wordmark-tag">BEAUTY PRODUCTS TRADING</span>
    </a>

    @auth
        @can('owner')
            {{-- Owner tabs. On wide screens they sit next to the wordmark; below 992px they open from the Menu button. --}}
            <div class="collapse navbar-collapse order-last order-lg-1 flex-grow-0" id="ownerMenu">
                <nav class="nav nav-pills" aria-label="Owner menu">
                    <a class="nav-link {{ request()->is('pos*') ? 'active' : '' }}" href="{{ url('/pos') }}"
                       @if (request()->is('pos*')) aria-current="page" @endif>POS</a>
                    <a class="nav-link {{ request()->is('sales*') ? 'active' : '' }}" href="{{ url('/sales') }}"
                       @if (request()->is('sales*')) aria-current="page" @endif>Sales Records</a>
                    <a class="nav-link {{ request()->is('inventory*', 'products*') ? 'active' : '' }}" href="{{ url('/inventory') }}"
                       @if (request()->is('inventory*', 'products*')) aria-current="page" @endif>Inventory</a>
                    <a class="nav-link {{ request()->is('audit-log*') ? 'active' : '' }}" href="{{ url('/audit-log') }}"
                       @if (request()->is('audit-log*')) aria-current="page" @endif>Audit Log</a>
                </nav>
            </div>
        @endcan

        {{-- Right side: Alerts, who is signed in, Log out --}}
        <div class="d-flex align-items-center gap-3 ms-auto order-lg-2">
            <a href="{{ url('/alerts') }}" class="jr-alerts-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.7 21a2 2 0 01-3.4 0"></path>
                </svg>
                Alerts
                <span class="jr-alerts-count">{{ $openAlertCount }}</span>
            </a>

            <div class="jr-signed-in">
                <span class="d-none d-xl-inline">Signed in as</span>
                <strong>{{ auth()->user()->role === 'OWNER' ? 'Owner' : 'Cashier' }}</strong>
            </div>

            <form method="POST" action="{{ url('/logout') }}" class="m-0">
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-pill">Log out</button>
            </form>

            @can('owner')
                <button class="navbar-toggler jr-menu-btn" type="button" data-bs-toggle="collapse"
                        data-bs-target="#ownerMenu" aria-controls="ownerMenu" aria-expanded="false"
                        aria-label="Show menu">Menu</button>
            @endcan
        </div>
    @endauth
</header>
