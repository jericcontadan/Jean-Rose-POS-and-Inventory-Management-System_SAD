{{--
    Sales Records (screen 7), OWNER only. Mockup: docs/design/07-sales-records-void.png (left part).
    Rows are grouped by sale: one header row per receipt (S-0001, time, cashier), then one row per sale_item.
    Price is sale_item.unit_price (the price when it was sold), never today's product price.
    CANCELLED (voided) sales are shown grey with their reason and are NOT in the totals.
    READ ONLY: Void (Step 7) and Export to Excel (Step 12) are shown but disabled. Close only shows a summary.
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
                                        {{-- Built in Step 7 (Void a Sale): shown, not working yet. --}}
                                        <button type="button" class="btn jr-btn-void jr-btn-row" disabled>Void</button>
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
    })();
</script>
@endpush
