{{--
    Dashboard, OWNER only (the owner's home page after login). No mockup screen: built from the same
    pink cards, badges and table as Inventory and Sales Records (docs/design/design-tokens.md).
    Needs $today, $dashboard (SaleService::dashboard) and $alertCounts (DashboardController). The view only shows them.
    Money counts PAID sales and SOLD items only, the same rules as Sales Records.
    Exception: "Voided today (any sale day)" counts by the day of the void (voided_at), not the sale day.
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@php
    $peso = fn ($value) => '₱'.number_format((float) $value, 2);
    $salesToday = route('sales.index', ['date' => $today->format('Y-m-d')]);
    $summary = $dashboard['today'];

    // Alert type -> badge class and the text shown on the card.
    $alertCards = [
        'EXPIRED' => ['class' => 'jr-badge-expired', 'label' => 'Expired'],
        'OUT OF STOCK' => ['class' => 'jr-badge-out', 'label' => 'Out of stock'],
        'NEAR EXPIRY' => ['class' => 'jr-badge-near', 'label' => 'Near expiry'],
        'LOW STOCK' => ['class' => 'jr-badge-low', 'label' => 'Low stock'],
    ];
@endphp

@section('content')
    <div class="jr-dash">
        <div class="jr-inv-head">
            <h1 class="jr-page-title">Dashboard</h1>
            <span class="jr-dash-date">{{ $today->format('l, F j, Y') }}</span>
        </div>

        {{-- Sales numbers. Each card opens Sales Records for today. --}}
        <section class="jr-dash-cards" aria-label="Sales today">
            <a href="{{ $salesToday }}" class="jr-dash-card">
                <span class="jr-dash-label">Today's sales</span>
                <span class="jr-dash-value" id="dashTodayTotal">{{ $peso($summary['total_sales']) }}</span>
                <span class="jr-dash-note"><strong id="dashTodayPaid">{{ $summary['paid_count'] }}</strong> paid {{ Str::plural('sale', $summary['paid_count']) }}</span>
            </a>
            <a href="{{ $salesToday }}" class="jr-dash-card">
                <span class="jr-dash-label">This month</span>
                <span class="jr-dash-value" id="dashMonthTotal">{{ $peso($dashboard['month_total']) }}</span>
                <span class="jr-dash-note">{{ $today->format('F Y') }}, paid sales</span>
            </a>
            {{-- Counted by the day of the VOID, not the sale day (a sale from yesterday voided today is here). --}}
            <a href="{{ $salesToday }}" class="jr-dash-card">
                <span class="jr-dash-label">Voided today (any sale day)</span>
                <span class="jr-dash-value">
                    <span id="dashVoidedSales">{{ $dashboard['voided_sales'] }}</span> {{ Str::plural('sale', $dashboard['voided_sales']) }}
                    · <span id="dashVoidedItems">{{ $dashboard['voided_items'] }}</span> {{ Str::plural('item', $dashboard['voided_items']) }}
                </span>
                <span class="jr-dash-note">Refunded <strong id="dashRefund">{{ $peso($dashboard['refund_total']) }}</strong></span>
            </a>
            <a href="{{ $salesToday }}" class="jr-dash-card">
                <span class="jr-dash-label">Senior / PWD today</span>
                <span class="jr-dash-value" id="dashSeniorAmount">{{ $peso($dashboard['senior_amount']) }}</span>
                <span class="jr-dash-note"><strong id="dashSeniorCount">{{ $dashboard['senior_count'] }}</strong> discounted {{ Str::plural('sale', $dashboard['senior_count']) }}</span>
            </a>
        </section>

        {{-- Open alerts by type. Each card opens the Alerts page (its "View" links go to Inventory for the owner). --}}
        <section aria-labelledby="dashAlertsTitle">
            <h2 id="dashAlertsTitle" class="jr-dash-title">Open alerts</h2>
            <div class="jr-dash-cards">
                @foreach ($alertCards as $type => $card)
                    <a href="{{ route('alerts.index') }}" class="jr-dash-card" aria-label="{{ $card['label'] }}: {{ $alertCounts[$type] }}">
                        <span class="{{ $card['class'] }} align-self-start">{{ $card['label'] }}</span>
                        <span class="jr-dash-value">{{ $alertCounts[$type] }}</span>
                        <span class="jr-dash-note">{{ $type === 'EXPIRED' ? 'Remove from the shelf' : 'View alerts' }}</span>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- The 5 newest sales, paid or voided, each with a link to its receipt. --}}
        <section aria-labelledby="dashLatestTitle">
            <div class="d-flex justify-content-between align-items-center">
                <h2 id="dashLatestTitle" class="jr-dash-title">Latest sales</h2>
                <a href="{{ $salesToday }}" class="btn btn-outline-primary btn-pill jr-btn-row">All sales today</a>
            </div>
            <div class="jr-inv-table-wrap mt-2">
                <table class="table align-middle mb-0 jr-dash-table">
                    <thead>
                        <tr>
                            <th scope="col">Receipt</th>
                            <th scope="col">Date and time</th>
                            <th scope="col">Cashier</th>
                            <th scope="col" class="text-end">Total</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="visually-hidden">Action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($dashboard['latest'] as $sale)
                            <tr>
                                <td class="fw-bold">{{ $sale->receiptNumber() }}</td>
                                <td>{{ $sale->sale_datetime->format('M j, Y g:i A') }}</td>
                                <td>{{ $sale->cashier?->username ?? '-' }}</td>
                                <td class="text-end">{{ $peso($sale->total_amount) }}</td>
                                <td>
                                    @if ($sale->sale_status === 'PAID')
                                        <span class="jr-badge-ok jr-badge-table">PAID</span>
                                    @else
                                        <span class="jr-badge-voided">VOIDED</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('receipts.show', $sale) }}" class="btn btn-outline-primary btn-pill jr-btn-row">Receipt</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center jr-dash-note py-4">No sales yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
