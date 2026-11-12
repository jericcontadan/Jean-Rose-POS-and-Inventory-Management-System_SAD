{{--
    Alerts (screen 6), OWNER and CASHIER. Mockup: docs/design/08-alerts-daily-email.png.
    Left card = this page: open alerts grouped LOW STOCK, OUT OF STOCK, NEAR EXPIRY.
    Right = the "Who gets what" box. (The mockup's email card is the template for the daily email, not shown here.)
    $viewRoute is chosen in AlertController: inventory.index for the owner, pos.index for the cashier.
--}}
@extends('layouts.app')

@section('title', 'Alerts')

@php
    // Badge class for each alert type (same badges as the POS cards and Inventory).
    $badgeClass = ['LOW STOCK' => 'jr-badge-low', 'OUT OF STOCK' => 'jr-badge-out', 'NEAR EXPIRY' => 'jr-badge-near'];
@endphp

@section('content')
    <div class="jr-alerts-page">
        <section class="card jr-alerts-card" aria-labelledby="alertsTitle">
            <div class="d-flex justify-content-between align-items-center">
                <h1 id="alertsTitle" class="jr-alerts-title">Alerts ({{ $count }})</h1>
                <a href="{{ route('pos.index') }}" class="jr-icon-btn" aria-label="Close alerts">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"></path></svg>
                </a>
            </div>

            @forelse ($groups as $type => $alerts)
                <h2 class="jr-alerts-group">{{ $type }} ({{ $alerts->count() }})</h2>

                @foreach ($alerts as $alert)
                    @php
                        $batch = $alert->batch;
                        $product = $batch->product;
                    @endphp
                    <div class="jr-alert-tile">
                        <span class="{{ $badgeClass[$type] }} align-self-start">{{ $type }}</span>
                        <span class="jr-alert-line">{{ $product->product_name }} · {{ $batch->inventory?->quantity_on_hand ?? 0 }} left</span>
                        <span class="jr-alert-note">
                            Batch {{ $batch->batch_number }} · expires {{ $batch->expiry_date->format('m/d/Y') }}
                            @if ($type === 'LOW STOCK')
                                · reorder level {{ $batch->inventory?->reorder_level }}
                            @elseif ($type === 'NEAR EXPIRY')
                                · discounted on sale
                            @endif
                        </span>
                        <a href="{{ route($viewRoute, ['q' => $product->product_name]) }}"
                           class="btn btn-outline-primary jr-alert-view">View</a>
                    </div>
                @endforeach
            @empty
                <p class="jr-alert-empty">No open alerts. Stock and expiry dates are all okay.</p>
            @endforelse

            <p class="jr-alert-note mb-0">Each alert is shown once.</p>
        </section>

        <aside class="card jr-alerts-card jr-alerts-side" aria-labelledby="whoTitle">
            <h2 id="whoTitle" class="jr-alerts-side-title">Who gets what</h2>
            <div class="jr-alert-tile">
                <span class="jr-who-role">Cashier</span>
                <span>In the system: Alerts button and product badges</span>
            </div>
            <div class="jr-alert-tile">
                <span class="jr-who-role">Owner</span>
                <span>In the system and one daily email (Gmail)</span>
            </div>
        </aside>
    </div>
@endsection
