{{--
    Sales Records (screen 7), OWNER only. Mockup: docs/design/07-sales-records-void.png (left part).
    Rows are grouped by sale: one header row per receipt (S-0001, time, cashier), then one row per sale_item.
    Price is sale_item.unit_price (the price when it was sold), never today's product price.
    CANCELLED (voided) sales are shown grey with their reason and are NOT in the totals.
    Void (screen 8): the Void button opens the void card on the right (?void=<sale id>, mockup right part).
    Confirm void posts to sales.void; SaleService::void() does the work. Export to Excel (Step 12) is shown but disabled.
    All data comes from SalesRecordController / SaleService; this view only shows it.
--}}
@extends('layouts.app')

@section('title', 'Sales Records')

@php
    // 10.00 -> "10%", 0.00 -> "0%"
    $percent = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').'%';
    $peso = fn ($value) => '₱'.number_format((float) $value, 2);
@endphp

@section('content')
    <div class="jr-sales">
        {{-- Title, date picker and page buttons --}}
        <div class="jr-sales-head">
            <h1 class="jr-page-title">Sales Records</h1>

            <div class="jr-sales-head-actions">
                {{-- Changing the date reloads the page for that day (script below). "Show" works without JavaScript. --}}
                <form method="GET" action="{{ route('sales.index') }}" class="jr-sales-date-form" id="salesDateForm">
                    <div class="jr-sales-date-field">
                        <label for="salesDate" class="jr-sales-date-label">Date</label>
                        <input id="salesDate" name="date" type="date" required
                               class="form-control jr-sales-date @error('date') is-invalid @enderror"
                               value="{{ $date->format('Y-m-d') }}">
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-pill">Show</button>
                </form>

                {{-- Built in Step 12 (Excel export): shown, not working yet. --}}
                <button type="button" class="btn btn-outline-primary btn-pill" disabled>Export to Excel</button>

                <button type="button" class="btn btn-primary btn-pill" data-bs-toggle="modal" data-bs-target="#closeSalesModal">Close</button>
            </div>
        </div>

        @error('date')
            <div class="alert alert-danger mb-0" role="alert">{{ $message }}</div>
        @enderror

        {{-- Left: table and summary. Right: the void card (only when a sale was chosen). --}}
        <div class="jr-sales-layout">
        <div class="jr-sales-main">

        {{-- One <tbody> per sale: a header row (receipt), then its items --}}
        <div class="jr-sales-table-wrap">
            <table class="table jr-sales-table">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Category</th>
                        <th scope="col">Product</th>
                        <th scope="col" class="text-end">Quantity</th>
                        <th scope="col" class="text-end">Price</th>
                        <th scope="col" class="text-end">Discount</th>
                        <th scope="col" class="text-end">Total Amount</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>

                @forelse ($sales as $sale)
                    @php($voided = $sale->sale_status === 'CANCELLED')
                    <tbody class="jr-sales-group {{ $voided ? 'jr-sales-voided' : '' }}">
                        <tr class="jr-sales-sale-row">
                            <td colspan="7">
                                <span class="jr-sales-receipt">{{ $sale->receiptNumber() }}</span>
                                <span class="jr-sales-meta">{{ $sale->sale_datetime->format('h:i A') }} · {{ $sale->cashier?->username }}</span>
                                @if ($voided)
                                    <span class="jr-badge-voided">VOIDED</span>
                                    <span class="jr-sales-reason">Reason: {{ $sale->void_reason }}</span>
                                @else
                                    <span class="jr-sales-meta">· Total {{ $peso($sale->total_amount) }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="jr-sales-actions">
                                    {{-- Reprint: opens the saved receipt (it has its own Print button). --}}
                                    <a href="{{ route('receipts.show', $sale) }}" class="btn btn-outline-primary jr-btn-row">Receipt</a>
                                    @unless ($voided)
                                        {{-- Opens the void card for this sale (a normal link: works by touch, no script needed). --}}
                                        @php($selected = $voidSale?->sale_id === $sale->sale_id)
                                        <a href="{{ route('sales.index', ['date' => $date->format('Y-m-d'), 'void' => $sale->sale_id]) }}"
                                           class="btn jr-btn-void jr-btn-row {{ $selected ? 'jr-btn-void-on' : '' }}"
                                           @if ($selected) aria-current="true" @endif>Void</a>
                                    @endunless
                                </div>
                            </td>
                        </tr>

                        {{-- One row per sale_item (a FEFO split shows the same product twice, one row per batch) --}}
                        @foreach ($sale->items as $item)
                            <tr class="jr-sales-item-row">
                                <td class="text-nowrap">{{ $sale->sale_datetime->format('m/d/Y') }}</td>
                                <td>{{ $item->batch->product->category->category_name }}</td>
                                <td class="fw-bold">{{ $item->batch->product->product_name }}</td>
                                <td class="text-end">{{ $item->quantity }}</td>
                                <td class="jr-money">{{ $peso($item->unit_price) }}</td>
                                <td class="text-end">{{ $percent($item->discount_percent) }}</td>
                                <td class="jr-money fw-bold">{{ $peso($item->line_amount) }}</td>
                                <td></td>
                            </tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody>
                        <tr>
                            <td colspan="8" class="jr-empty">No sales on {{ $date->format('m/d/Y') }}.</td>
                        </tr>
                    </tbody>
                @endforelse
            </table>
        </div>

        {{-- Page links only appear when the day has more than 50 sales --}}
        @if ($sales->hasPages())
            <div class="jr-sales-pages">{{ $sales->links() }}</div>
        @endif

        {{-- Daily summary for the whole day (paid only in the total) --}}
        <div class="jr-sales-summary" aria-label="Daily summary">
            <span class="jr-sales-summary-item">Paid transactions <strong id="summaryPaid">{{ $summary['paid_count'] }}</strong></span>
            <span class="jr-sales-summary-item">Cancelled <strong id="summaryCancelled">{{ $summary['cancelled_count'] }}</strong></span>
            <span class="jr-sales-summary-item">
                Total sales <span class="jr-sales-total" id="summaryTotal">{{ $peso($summary['total_sales']) }}</span>
            </span>
        </div>
        </div>{{-- /.jr-sales-main --}}

        @if ($voidSale)
            {{-- Void card (mockup right part). This card is the "confirm before void" step. --}}
            <form method="POST" action="{{ route('sales.void', $voidSale) }}" class="card jr-void-card" id="voidForm">
                @csrf
                <h2 class="jr-void-title">Void sale {{ $voidSale->receiptNumber() }}</h2>

                <div class="jr-void-selected">
                    <span class="jr-void-selected-label">SELECTED SALE</span>
                    <span class="fw-bold">{{ $voidSale->sale_datetime->format('m/d/Y H:i') }}</span>
                    <span>{{ $voidItems }}</span>
                    <span class="jr-void-selected-total">Total {{ $peso($voidSale->total_amount) }}</span>
                </div>

                <div class="d-flex flex-column gap-2">
                    <label for="voidReason" class="jr-void-label">Reason *</label>
                    <textarea id="voidReason" name="reason" rows="3" required minlength="5" maxlength="247"
                              placeholder="Example: wrong item entered"
                              class="form-control jr-void-reason @error('reason') is-invalid @enderror"
                              @error('reason') aria-describedby="voidReasonError" @enderror>{{ old('reason') }}</textarea>
                    @error('reason')
                        <div class="invalid-feedback d-block" id="voidReasonError">{{ $message }}</div>
                    @enderror
                </div>

                <div class="jr-void-note">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4M12 8h.01"></path></svg>
                    <span>Stock will be returned to inventory. This is saved in the audit log.</span>
                </div>

                <div class="jr-void-buttons">
                    <a href="{{ route('sales.index', ['date' => $date->format('Y-m-d')]) }}" class="btn btn-outline-primary jr-void-cancel">Cancel</a>
                    <button type="submit" class="btn jr-btn-confirm-void" id="confirmVoid">Confirm void</button>
                </div>
            </form>
        @endif
        </div>{{-- /.jr-sales-layout --}}
    </div>

    {{-- Close Sales: shows the day's summary only. Nothing is saved (the schema has no Close Sales table). --}}
    <div class="modal fade" id="closeSalesModal" tabindex="-1" aria-labelledby="closeSalesTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered jr-pay-dialog">
            <div class="modal-content jr-modal jr-pay-card">
                <h2 class="jr-pay-title" id="closeSalesTitle">Close Sales</h2>
                <p class="jr-sales-close-date mb-0">{{ $date->format('m/d/Y') }}</p>

                <dl class="jr-sales-close-list mb-0">
                    <div><dt>Paid transactions</dt><dd>{{ $summary['paid_count'] }}</dd></div>
                    <div><dt>Cancelled</dt><dd>{{ $summary['cancelled_count'] }}</dd></div>
                    <div><dt>Total discount</dt><dd>{{ $peso($summary['total_discount']) }}</dd></div>
                </dl>

                <div class="jr-pay-total">
                    <span class="jr-pay-label">TOTAL SALES (PAID ONLY)</span>
                    <span class="jr-pay-total-amount">{{ $peso($summary['total_sales']) }}</span>
                </div>

                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Picking a date shows that day right away (the GET form is sent; nothing is saved).
    (function () {
        const input = document.getElementById('salesDate');
        input.addEventListener('change', function () {
            if (input.value) {
                document.getElementById('salesDateForm').submit();
            }
        });

        // Confirm void: disable the button after the first tap so it is sent only once.
        // (The server also refuses a second void of the same sale.)
        const voidForm = document.getElementById('voidForm');
        if (voidForm) {
            voidForm.addEventListener('submit', function () {
                document.getElementById('confirmVoid').disabled = true;
            });
        }
    })();
</script>
@endpush
