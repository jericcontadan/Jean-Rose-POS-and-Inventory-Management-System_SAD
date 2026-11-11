{{-- Temporary page to preview the layout. Step 1 (login) replaces "/" with a redirect. --}}
@extends('layouts.app')

@section('title', 'Home')

@section('content')
    <h1 class="jr-page-title mb-4">Jean Rose POS</h1>

    <div class="card p-4">
        <p class="mb-3">Setup complete. The layout, colors and fonts are ready.</p>
        <div class="d-flex flex-wrap gap-2">
            <span class="jr-badge-ok">OK</span>
            <span class="jr-badge-low">Low stock</span>
            <span class="jr-badge-near">Near expiry</span>
            <span class="jr-badge-out">Out of stock</span>
        </div>
    </div>
@endsection
