{{--
    Inventory (screen 9), OWNER only. Mockup: docs/design/05-inventory-owner.png.
    One table row per batch (stock and expiry are kept per batch). The status pill is inventory.stock_status as stored.
    Update stock and Update price (screen 10), + Add product, Edit and the trash (screen 12) work.
    Import and Export are shown but disabled until their own build step.
    All data comes from InventoryController / InventoryService; this view only shows it.
--}}
@extends('layouts.app')

@section('title', 'Inventory')

@php
    // Stored status -> pill class and the text shown (OKAY is shown as OK, like the mockup).
    $pills = [
        'OUT OF STOCK' => ['class' => 'jr-badge-out', 'label' => 'OUT OF STOCK'],
        'NEAR EXPIRY' => ['class' => 'jr-badge-near', 'label' => 'NEAR EXPIRY'],
        'LOW STOCK' => ['class' => 'jr-badge-low', 'label' => 'LOW STOCK'],
        'OKAY' => ['class' => 'jr-badge-ok', 'label' => 'OK'],
    ];
@endphp

@section('content')
    <div class="jr-inv">
        {{-- Title and the page buttons (Import and Export are built in a later step) --}}
        <div class="jr-inv-head">
            <h1 class="jr-page-title">Inventory</h1>
            <div class="jr-inv-head-actions">
                <a href="{{ route('products.create') }}" class="btn btn-primary btn-pill">+ Add product</a>
                <button type="button" class="btn btn-outline-primary btn-pill" disabled>Import Excel</button>
                <button type="button" class="btn btn-outline-primary btn-pill" disabled>Export Excel</button>
            </div>
        </div>

        {{-- Summary cards: count of batches with that status, and the first one as an example --}}
        <div class="jr-inv-cards">
            @foreach ($summary as $status => $card)
                <section class="jr-inv-card" aria-label="{{ $pills[$status]['label'] }}">
                    <span class="{{ $pills[$status]['class'] }} align-self-start">{{ $pills[$status]['label'] }}</span>
                    <span class="jr-inv-card-count">{{ $card['count'] }}</span>
                    <span class="jr-inv-card-first">{{ $card['first'] }}</span>
                </section>
            @endforeach
        </div>

        {{-- Search: typing filters the rows right away (script below). Enter sends the form, so it also works without JavaScript. --}}
        <form method="GET" action="{{ route('inventory.index') }}" class="jr-inv-search" role="search" id="invSearchForm">
            <div class="jr-search jr-inv-search-box">
                <label for="q" class="visually-hidden">Search product</label>
                <svg class="jr-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path>
                </svg>
                <input id="q" name="q" type="search" class="form-control jr-inv-search-input" placeholder="Search product"
                       value="{{ $search }}" maxlength="100" autocomplete="off">
            </div>
            <button type="button" class="btn jr-btn-clear btn-pill" id="invSearchClear">Clear</button>
        </form>

        {{-- One row per batch --}}
        <div class="jr-inv-table-wrap">
            <table class="table jr-inv-table">
                <thead>
                    <tr>
                        <th scope="col">List of products</th>
                        <th scope="col" class="text-end">Price</th>
                        <th scope="col" class="text-end">Stocks</th>
                        <th scope="col">Supplier</th>
                        <th scope="col">Status</th>
                        <th scope="col">Expiration date</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody id="invRows">
                    @foreach ($rows as $row)
                        @php($pill = $pills[$row['status']] ?? $pills['OKAY'])
                        <tr data-search="{{ mb_strtolower($row['product_name'].' '.$row['product_number'].' '.$row['batch_number'].' '.$row['supplier']) }}"
                            @unless ($row['matches']) hidden @endunless>
                            <td>
                                <span class="jr-inv-name">{{ $row['product_name'] }}</span>
                                <span class="jr-inv-batch">Batch {{ $row['batch_number'] }}</span>
                            </td>
                            <td class="jr-money">₱{{ number_format($row['unit_price'], 2) }}</td>
                            <td class="text-end">{{ $row['quantity'] }}</td>
                            <td>{{ $row['supplier'] ?? '—' }}</td>
                            <td><span class="{{ $pill['class'] }} jr-badge-table">{{ $pill['label'] }}</span></td>
                            <td class="text-nowrap">
                                {{ $row['expiry_date']->format('m/d/Y') }}
                                @if ($row['expired'])
                                    <span class="jr-inv-expired">Expired</span>
                                @endif
                            </td>
                            <td>
                                {{-- Update stock (this batch), Update price and Edit (this product) open their own page.
                                     The trash deletes this batch after a confirm; the server refuses it if the batch
                                     has sales or history (InventoryService::deleteBatch). --}}
                                <div class="jr-inv-actions">
                                    <a href="{{ route('inventory.stock.edit', $row['inventory_id']) }}"
                                       class="btn jr-btn-row {{ in_array($row['status'], ['LOW STOCK', 'OUT OF STOCK']) ? 'btn-primary' : 'btn-outline-primary' }}">Update stock</a>
                                    <a href="{{ route('products.price.edit', $row['product_id']) }}" class="btn btn-outline-primary jr-btn-row">Update price</a>
                                    <a href="{{ route('products.edit', $row['product_id']) }}" class="btn btn-outline-primary jr-btn-row">Edit</a>
                                    <form method="POST" action="{{ route('inventory.destroy', $row['inventory_id']) }}" class="m-0"
                                          data-confirm="Delete batch {{ $row['batch_number'] }} of {{ $row['product_name'] }}? This cannot be undone.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn jr-btn-trash" aria-label="Delete {{ $row['product_name'] }} batch {{ $row['batch_number'] }}">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"></path>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    {{-- Shown only when no row is visible (the script updates it while typing). --}}
                    <tr id="invNoRows" @if ($rows->contains('matches', true)) hidden @endif>
                        <td colspan="7" class="jr-empty">No products found.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Live inventory search (plain JavaScript, same idea as the POS search).
    // It only shows or hides rows that are already on the page.
    (function () {
        const input = document.getElementById('q');
        const clearBtn = document.getElementById('invSearchClear');
        const rows = document.querySelectorAll('#invRows tr[data-search]');
        const noRows = document.getElementById('invNoRows');

        function applySearch() {
            const text = input.value.trim().toLowerCase();
            let shown = 0;

            // Show a row when its product name, product number, batch number or supplier contains the text.
            rows.forEach(function (row) {
                const match = text === '' || row.dataset.search.includes(text);
                row.hidden = !match;
                if (match) shown++;
            });

            noRows.hidden = shown > 0;

            // Keep ?q= in the address bar, so a page refresh keeps the same search.
            const url = new URL(window.location.href);
            text === '' ? url.searchParams.delete('q') : url.searchParams.set('q', input.value.trim());
            history.replaceState(null, '', url);
        }

        input.addEventListener('input', applySearch); // every key, paste, and the browser's own clear

        // Trash: ask before deleting a batch (the text is in each form's data-confirm).
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!confirm(form.dataset.confirm)) event.preventDefault();
            });
        });

        clearBtn.addEventListener('click', function () {
            input.value = '';
            applySearch();
            input.focus();
        });
    })();
</script>
@endpush
