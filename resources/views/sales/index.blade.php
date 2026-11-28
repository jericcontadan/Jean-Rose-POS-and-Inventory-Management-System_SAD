{{--
    Sales Records (screen 7), OWNER only. Mockup: docs/design/07-sales-records-void.png (left part).
    Top: title with the date in words, date picker, Export, End-of-day summary; then 5 summary cards for the whole day
    (each compared with the day before), then search and filters (receipt no., status, discount, cashier) for the table.
    No sale matches: an empty state instead of the table. Below 600px each sale is a card (see jeanrose.css).
    Table: one row per sale: Receipt No | Time | Cashier | Qty sold | Discount | Total | Status | Actions (20 sales per page).
    Tapping the arrow or anywhere on a sale row opens its items (Product, Qty, Price, Discount, Amount, Status) in a panel under it.
    Price is sale_item.unit_price (the price when it was sold), never today's product price.
    CANCELLED (voided) sales are shown grey with who voided them, when and why, and are NOT in the totals.
    Void (screen 8): the Void button opens the void card (?void=<sale id>, mockup right part). That card is the confirm step.
    Confirm void posts to sales.void; SaleService::void() does the work.
    Void one item: each SOLD item of a PAID sale has a "Void item" button (?void_item=<sale_item id>) that opens the
    void item card; it posts to sales.items.void and SaleService::voidItem() does the work.
    A sale with voided items shows "1 item voided" and its original total struck through; the panel ends with Original / Refunded / Final.
    Export to Excel downloads this day's Sales report (ExportController).
    All data comes from SalesRecordController / SaleService; this view only shows it.
--}}
@extends('layouts.app')

@section('title', 'Sales Records')

{{-- Read-only helpers for the Original / Refunded numbers (SaleService::originalTotal, itemRefund). --}}
@inject('salesService', 'App\Services\SaleService')

@php
    // 10.00 -> "10%", 0.00 -> "—"
    $percent = fn ($value) => (float) $value > 0 ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').'%' : '—';
    $peso = fn ($value) => '₱'.number_format((float) $value, 2);
    // Money in the item panel: "—" instead of ₱0.00
    $pesoOrDash = fn ($value) => (float) $value != 0 ? '₱'.number_format((float) $value, 2) : '—';
    // "Voided by owner at 10:05 AM · wrong item" (parts that are missing are left out)
    $voidNote = fn ($row, $what = 'Voided') => collect([
        $what.($row->voidedBy ? ' by '.$row->voidedBy->username : '').($row->voided_at ? ' at '.$row->voided_at->format('h:i A') : ''),
        $row->void_reason,
    ])->filter()->implode(' · ');
    // Card comparison with the day before: ['▲ 12% vs yesterday', 'is-up'] (green), ▼ = down (red),
    // '—' when yesterday had nothing to compare with (no division by zero).
    $change = function ($now, $before) {
        $now = (float) $now;
        $before = (float) $before;
        if ($before == 0) {
            return ['— vs yesterday', 'is-none'];
        }
        $pct = (int) round(($now - $before) / $before * 100);

        return match (true) {
            $pct > 0 => ['▲ '.$pct.'% vs yesterday', 'is-up'],
            $pct < 0 => ['▼ '.abs($pct).'% vs yesterday', 'is-down'],
            default => ['0% vs yesterday', 'is-none'],
        };
    };
    $cards = [
        ['Total sales', 'summaryTotal', $peso($summary['total_sales']), 'Paid sales only', 'total_sales'],
        ['Paid transactions', 'summaryPaid', $summary['paid_count'], 'Receipts paid', 'paid_count'],
        ['Cancelled', 'summaryCancelled', $summary['cancelled_count'], 'Whole sales voided', 'cancelled_count'],
        ['Discounts given', 'summaryDiscount', $peso($summary['total_discount']), 'Senior/PWD + near-expiry', 'total_discount'],
        ['Refunded', 'summaryRefunded', $peso($summary['refunded']), 'Voided items', 'refunded'],
    ];
@endphp

@section('content')
    <div class="jr-sales">
        {{-- Title with the day in words; date picker and page buttons on the right --}}
        <div class="jr-sales-head">
            <div>
                <h1 class="jr-page-title">Sales Records</h1>
                <div class="jr-sales-day" id="salesDay">{{ $date->format('l, F j, Y') }}</div>
            </div>

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

                {{-- The Sales report of the day shown (From = To = this date). Owner only, also checked on the server. --}}
                @can('owner')
                    <a href="{{ route('exports.download', ['report' => 'sales', 'from' => $date->format('Y-m-d'), 'to' => $date->format('Y-m-d')]) }}"
                       class="btn btn-outline-primary btn-pill">Export to Excel</a>
                @endcan

                {{-- Opens the "Close Sales" box: the day's totals to read out when closing the shop (nothing is saved). --}}
                <button type="button" class="btn btn-outline-primary btn-pill" data-bs-toggle="modal" data-bs-target="#closeSalesModal">End-of-day summary</button>
            </div>
        </div>

        @error('date')
            <div class="alert alert-danger mb-0" role="alert">{{ $message }}</div>
        @enderror

        {{-- Export to Excel refused (for example a date in the future): the message comes back in the "export" bag. --}}
        @if ($errors->getBag('export')->any())
            <div class="alert alert-danger mb-0" role="alert">{{ $errors->getBag('export')->first() }}</div>
        @endif

        {{-- Summary of the WHOLE day (not only this page). Money counts PAID sales only. --}}
        <section class="jr-sales-cards" aria-label="Daily summary">
            @foreach ($cards as [$label, $id, $value, $note, $key])
                @php
                    [$changeText, $changeClass] = $change($summary[$key], $previous[$key]);
                @endphp
                <div class="jr-dash-card">
                    <span class="jr-dash-label">{{ $label }}</span>
                    <span class="jr-dash-value {{ $key === 'total_sales' ? 'jr-sales-card-total' : '' }}" id="{{ $id }}">{{ $value }}</span>
                    <span class="jr-dash-note">{{ $note }}</span>
                    <span class="jr-sales-change {{ $changeClass }}">{{ $changeText }}</span>
                </div>
            @endforeach
        </section>

        {{-- Search and filters (GET form: nothing is saved). They keep the date; the page links keep them. --}}
        <form method="GET" action="{{ route('sales.index') }}" class="jr-sales-filters" id="salesFilters" role="search">
            <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}">
            <div class="jr-sales-filter jr-sales-filter-q">
                <label for="filterQ" class="jr-sales-date-label">Receipt no.</label>
                <input id="filterQ" name="q" type="search" class="form-control" maxlength="20" autocomplete="off"
                       placeholder="e.g. S-0012" value="{{ $filters['q'] ?? '' }}">
            </div>
            <div class="jr-sales-filter">
                <label for="filterStatus" class="jr-sales-date-label">Status</label>
                <select id="filterStatus" name="status" class="form-select">
                    @foreach (['' => 'All', 'PAID' => 'Paid', 'CANCELLED' => 'Cancelled', 'VOIDED_ITEMS' => 'With voided items'] as $value => $text)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $text }}</option>
                    @endforeach
                </select>
            </div>
            <div class="jr-sales-filter">
                <label for="filterDiscount" class="jr-sales-date-label">Discount</label>
                <select id="filterDiscount" name="discount" class="form-select">
                    @foreach (['' => 'All', 'SENIOR' => 'Senior', 'PWD' => 'PWD', 'NEAR_EXPIRY' => 'Near-expiry', 'NONE' => 'None'] as $value => $text)
                        <option value="{{ $value }}" @selected(($filters['discount'] ?? '') === $value)>{{ $text }}</option>
                    @endforeach
                </select>
            </div>
            <div class="jr-sales-filter">
                <label for="filterCashier" class="jr-sales-date-label">Cashier</label>
                <select id="filterCashier" name="cashier" class="form-select">
                    <option value="">All</option>
                    @foreach ($cashiers as $cashier)
                        <option value="{{ $cashier->user_id }}" @selected((int) ($filters['cashier'] ?? 0) === $cashier->user_id)>{{ $cashier->username }}</option>
                    @endforeach
                </select>
            </div>
            <div class="jr-sales-filter-buttons">
                <button type="submit" class="btn btn-primary btn-pill">Search</button>
                @if ($filters)
                    <a href="{{ route('sales.index', ['date' => $date->format('Y-m-d')]) }}" class="btn btn-outline-primary btn-pill">Clear</a>
                @endif
            </div>
        </form>

        {{-- Left: the table. Right (or under it on a tablet): the void card, only when a sale was chosen. --}}
        <div class="jr-sales-layout">
        <div class="jr-sales-main">

        {{-- One aligned table, one row per sale. The items of each sale are in a hidden row under it,
             with their own sub-header. Fixed column widths (see <colgroup>). --}}
        @if ($sales->isEmpty())
            {{-- Empty state: no table at all --}}
            <div class="jr-sales-empty" role="status">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"></path><path d="M9 7h6M9 11h6M9 15h4"></path></svg>
                <strong>No sales for this day</strong>
                @if ($filters)
                    <span>No sale on {{ $date->format('m/d/Y') }} matches these filters.</span>
                    <a href="{{ route('sales.index', ['date' => $date->format('Y-m-d')]) }}" class="btn btn-outline-primary btn-pill">Clear filters</a>
                @else
                    <span>Nothing was sold on {{ $date->format('m/d/Y') }}. Pick another date above.</span>
                @endif
            </div>
        @else
        <div class="jr-sales-table-wrap">
            <table class="table jr-sales-table">
                <colgroup>
                    <col class="jr-col-receipt">
                    <col class="jr-col-time">
                    <col class="jr-col-cashier">
                    <col class="jr-col-qtysold">
                    <col class="jr-col-money">
                    <col class="jr-col-money">
                    <col class="jr-col-status">
                    <col class="jr-col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Receipt No</th>
                        <th scope="col">Time</th>
                        <th scope="col">Cashier</th>
                        <th scope="col" class="text-end">Qty sold</th>
                        <th scope="col" class="text-end">Discount</th>
                        <th scope="col" class="text-end">Total</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>

                @foreach ($sales as $sale)
                    @php
                        $voided = $sale->sale_status === 'CANCELLED';
                        $open = $openSaleId === $sale->sale_id;
                        $voidedItems = $sale->items->filter->isVoided()->count();
                        $original = $salesService->originalTotal($sale);
                        $refund = $voided ? $original : ($salesService->itemRefund($sale) ?? 0.0);
                        $final = $voided ? 0.0 : (float) $sale->total_amount;
                        // Pieces sold (voided items are left out, except on a fully voided sale).
                        $pieces = $voided ? $sale->items->sum('quantity') : $sale->items->reject->isVoided()->sum('quantity');
                        // All discounts of the sale (near-expiry + Senior/PWD) = subtotal - total, and their type.
                        $discount = max(0, round((float) $sale->subtotal - (float) $sale->total_amount, 2));
                        $types = [];
                        if ($sale->items->contains(fn ($item) => (float) $item->discount_percent > 0)) {
                            $types[] = 'Near-expiry';
                        }
                        if ($sale->hasSeniorDiscount()) {
                            $types[] = $sale->discount_type === 'SENIOR' ? 'Senior' : 'PWD';
                        }
                    @endphp
                    <tbody class="jr-sales-group {{ $voided ? 'jr-sales-voided' : '' }} {{ $open ? 'is-open' : '' }}">
                        {{-- Tapping anywhere on this row (except a button or link) opens the items (script below). --}}
                        <tr class="jr-sales-sale-row" data-items="#saleItems{{ $sale->sale_id }}">
                            <td>
                                {{-- Arrow: shows / hides this sale's items (a real button for keyboard and screen readers) --}}
                                <button type="button" class="jr-sales-chevron" data-bs-toggle="collapse"
                                        data-bs-target="#saleItems{{ $sale->sale_id }}"
                                        aria-controls="saleItems{{ $sale->sale_id }}"
                                        aria-expanded="{{ $open ? 'true' : 'false' }}"
                                        aria-label="Show items of {{ $sale->receiptNumber() }}">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"></path></svg>
                                </button>
                                <span class="jr-sales-receipt">{{ $sale->receiptNumber() }}</span>
                            </td>
                            <td class="text-nowrap">{{ $sale->sale_datetime->format('h:i A') }}</td>
                            <td class="jr-sales-ellipsis">{{ $sale->cashier?->username }}</td>
                            <td class="text-end">{{ $pieces }}</td>
                            <td class="text-end">
                                @if ($discount > 0)
                                    <span class="jr-money">-{{ $peso($discount) }}</span>
                                    <span class="jr-sales-sub">{{ implode(' + ', $types) }}</span>
                                @else
                                    <span class="jr-sales-sub">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <span class="jr-money jr-sales-total-cell">{{ $peso($sale->total_amount) }}</span>
                                @if (! $voided && $voidedItems > 0)
                                    {{-- What was paid before the items were voided --}}
                                    <span class="jr-sales-sub jr-sales-original"><s>{{ $peso($original) }}</s></span>
                                @endif
                            </td>
                            <td>
                                <div class="jr-sales-status">
                                    @if ($voided)
                                        <span class="jr-badge-voided">VOIDED</span>
                                    @else
                                        <span class="jr-badge-ok">PAID</span>
                                        @if ($voidedItems > 0)
                                            <span class="jr-badge-itemvoid">{{ $voidedItems }} {{ Str::plural('item', $voidedItems) }} voided</span>
                                        @endif
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="jr-sales-actions">
                                    {{-- Reprint: opens the saved receipt (it has its own Print button). --}}
                                    <a href="{{ route('receipts.show', $sale) }}" class="btn btn-outline-primary jr-btn-row">Receipt</a>
                                    @if ($voided)
                                        {{-- Empty slot the size of the Void button, so the buttons line up in every row. --}}
                                        <span class="jr-btn-void-sm jr-btn-slot" aria-hidden="true"></span>
                                    @else
                                        {{-- Small red outline: looks different from Receipt. It only opens the void card (the confirm step). --}}
                                        @php($selected = $voidSale?->sale_id === $sale->sale_id)
                                        <a href="{{ route('sales.index', $baseQuery + ['void' => $sale->sale_id]) }}"
                                           class="btn jr-btn-void jr-btn-void-sm {{ $selected ? 'jr-btn-void-on' : '' }}"
                                           aria-label="Void sale {{ $sale->receiptNumber() }}"
                                           @if ($selected) aria-current="true" @endif>Void</a>
                                    @endif
                                </div>
                            </td>
                        </tr>

                        {{-- The items of this sale, hidden until the row or its arrow is tapped (Bootstrap collapse). --}}
                        <tr class="jr-sales-details-row">
                            <td colspan="8">
                                <div class="collapse {{ $open ? 'show' : '' }}" id="saleItems{{ $sale->sale_id }}">
                                    <div class="jr-sales-details">
                                        @if ($voided || $sale->hasSeniorDiscount())
                                            <div class="jr-sales-details-note">
                                                @if ($voided)
                                                    <span class="jr-sales-reason">{{ $voidNote($sale, 'Sale voided') }}</span>
                                                @endif
                                                @if ($sale->hasSeniorDiscount())
                                                    {{-- Senior Citizen / PWD discount saved on the sale --}}
                                                    <span class="jr-badge-senior">{{ $sale->discount_type === 'SENIOR' ? 'Senior Citizen' : 'PWD' }} · ID {{ $sale->discount_id_number }} · {{ $sale->discount_customer_name }} · -{{ $peso($sale->discount_amount) }}</span>
                                                @endif
                                            </div>
                                        @endif

                                        {{-- One row per sale_item (a FEFO split shows the same product twice, one row per batch).
                                             A voided item stays in the list (grey, VOIDED, who / when / why) but is not in the sale's total. --}}
                                        <table class="table jr-sales-items">
                                            <colgroup>
                                                <col>
                                                <col class="jr-col-qty">
                                                <col class="jr-col-price">
                                                <col class="jr-col-pct">
                                                <col class="jr-col-amount">
                                                <col class="jr-col-istatus">
                                                <col class="jr-col-iaction">
                                            </colgroup>
                                            <thead>
                                                <tr>
                                                    <th scope="col">Product</th>
                                                    <th scope="col" class="text-end">Qty</th>
                                                    <th scope="col" class="text-end">Price</th>
                                                    <th scope="col" class="text-end">Discount</th>
                                                    <th scope="col" class="text-end">Amount</th>
                                                    <th scope="col">Status</th>
                                                    <th scope="col"><span class="visually-hidden">Action</span></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($sale->items as $item)
                                                    @php($itemVoided = $item->isVoided())
                                                    <tr class="{{ $itemVoided ? 'jr-item-voided' : '' }}">
                                                        <td>
                                                            <span class="fw-bold">{{ $item->displayName() }}</span>
                                                            @if ($itemVoided)
                                                                <span class="jr-sales-reason d-block">{{ $voidNote($item) }}</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-end" data-label="Qty">{{ $item->quantity }}</td>
                                                        <td class="jr-money" data-label="Price">{{ $pesoOrDash($item->unit_price) }}</td>
                                                        <td class="text-end" data-label="Disc.">{{ $percent($item->discount_percent) }}</td>
                                                        <td class="jr-money fw-bold jr-item-amount">{{ $pesoOrDash($item->line_amount) }}</td>
                                                        <td>
                                                            @if ($itemVoided || $voided)
                                                                <span class="jr-badge-voided">VOIDED</span>
                                                            @else
                                                                <span class="jr-badge-ok">SOLD</span>
                                                            @endif
                                                        </td>
                                                        <td class="text-end">
                                                            @if (! $voided && ! $itemVoided)
                                                                {{-- Opens the void item card for this line (owner only; the server checks it again). --}}
                                                                @php($itemSelected = $voidItem?->sale_item_id === $item->sale_item_id)
                                                                <a href="{{ route('sales.index', $baseQuery + ['void_item' => $item->sale_item_id]) }}"
                                                                   class="btn jr-btn-void jr-btn-void-sm jr-btn-void-item {{ $itemSelected ? 'jr-btn-void-on' : '' }}"
                                                                   @if ($itemSelected) aria-current="true" @endif>Void item</a>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>

                                        {{-- Phone only (below 600px the Actions column is hidden): the same buttons here --}}
                                        <div class="jr-sales-phone-actions">
                                            <a href="{{ route('receipts.show', $sale) }}" class="btn btn-outline-primary btn-pill">Receipt</a>
                                            @unless ($voided)
                                                <a href="{{ route('sales.index', $baseQuery + ['void' => $sale->sale_id]) }}" class="btn jr-btn-void jr-btn-void-sm">Void sale</a>
                                            @endunless
                                        </div>

                                        {{-- One line with the money story of this sale --}}
                                        <div class="jr-sales-money-line">
                                            Original {{ $peso($original) }} · Refunded {{ $peso($refund) }} · Final <strong>{{ $peso($final) }}</strong>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                @endforeach
            </table>
        </div>
        @endif

        {{-- Page links only appear when the day has more than 20 sales --}}
        @if ($sales->hasPages())
            <div class="jr-sales-pages">{{ $sales->links() }}</div>
        @endif

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
                    <a href="{{ route('sales.index', $baseQuery) }}" class="btn btn-outline-primary jr-void-cancel">Cancel</a>
                    <button type="submit" class="btn jr-btn-confirm-void" id="confirmVoid">Confirm void</button>
                </div>
            </form>
        @endif

        @if ($voidItem)
            {{-- Void item card: same look as the void card, for ONE line of the sale. --}}
            <form method="POST" action="{{ route('sales.items.void', $voidItem) }}" class="card jr-void-card" id="voidItemForm">
                @csrf
                <h2 class="jr-void-title">Void item from {{ $voidItem->sale->receiptNumber() }}</h2>

                <div class="jr-void-selected">
                    <span class="jr-void-selected-label">SELECTED ITEM</span>
                    <span class="fw-bold">{{ $voidItem->displayName() }} x{{ $voidItem->quantity }}</span>
                    <span>{{ $peso($voidItem->unit_price) }} each, discount {{ $percent($voidItem->discount_percent) }}</span>
                    <span class="jr-void-selected-total">Amount {{ $peso($voidItem->line_amount) }}</span>
                </div>

                <div class="d-flex flex-column gap-2">
                    <label for="voidItemReason" class="jr-void-label">Reason *</label>
                    <textarea id="voidItemReason" name="reason" rows="3" required minlength="5" maxlength="247"
                              placeholder="Example: item entered twice"
                              class="form-control jr-void-reason @error('reason') is-invalid @enderror"
                              @error('reason') aria-describedby="voidItemReasonError" @enderror>{{ old('reason') }}</textarea>
                    @error('reason')
                        <div class="invalid-feedback d-block" id="voidItemReasonError">{{ $message }}</div>
                    @enderror
                </div>

                <div class="jr-void-note">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4M12 8h.01"></path></svg>
                    <span>Stock for this item will be returned to inventory and the sale total is recalculated. This is saved in the audit log.</span>
                </div>

                <div class="jr-void-buttons">
                    <a href="{{ route('sales.index', $baseQuery + ['open' => $voidItem->sale_id]) }}" class="btn btn-outline-primary jr-void-cancel">Cancel</a>
                    <button type="submit" class="btn jr-btn-confirm-void" id="confirmVoidItem">Confirm void item</button>
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
                    <div><dt>Refunded (voided items)</dt><dd>{{ $peso($summary['refunded']) }}</dd></div>
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

        // Changing a filter list shows the result right away (the Search button does the same without JavaScript).
        document.querySelectorAll('#salesFilters select').forEach(function (select) {
            select.addEventListener('change', function () { document.getElementById('salesFilters').submit(); });
        });

        // Tapping a sale row opens / closes its items (taps on the Receipt or Void buttons and the arrow are left alone).
        document.querySelectorAll('.jr-sales-sale-row').forEach(function (row) {
            row.addEventListener('click', function (event) {
                if (event.target.closest('a, button')) {
                    return;
                }
                // toggle: false = do not open on create; .toggle() below does it once.
                bootstrap.Collapse.getOrCreateInstance(document.querySelector(row.dataset.items), { toggle: false }).toggle();
            });
        });

        // Keep the row highlighted (pink) while its items are open, and the arrow pointing up.
        document.querySelectorAll('.jr-sales-details-row .collapse').forEach(function (panel) {
            const group = panel.closest('.jr-sales-group');
            const arrow = group.querySelector('.jr-sales-chevron');
            panel.addEventListener('show.bs.collapse', function () {
                group.classList.add('is-open');
                arrow.setAttribute('aria-expanded', 'true');
            });
            panel.addEventListener('hide.bs.collapse', function () {
                group.classList.remove('is-open');
                arrow.setAttribute('aria-expanded', 'false');
            });
        });

        // On a tablet the void card sits under the table: scroll to it so the owner sees it right away.
        const card = document.querySelector('.jr-void-card');
        if (card && card.getBoundingClientRect().bottom > window.innerHeight) {
            card.scrollIntoView({ block: 'nearest' });
        }

        // Confirm void: disable the button after the first tap so it is sent only once.
        // (The server also refuses a second void of the same sale.)
        const voidForm = document.getElementById('voidForm');
        if (voidForm) {
            voidForm.addEventListener('submit', function () {
                document.getElementById('confirmVoid').disabled = true;
            });
        }

        // Confirm void item: same, sent only once (the server also refuses a second void of the same item).
        const voidItemForm = document.getElementById('voidItemForm');
        if (voidItemForm) {
            voidItemForm.addEventListener('submit', function () {
                document.getElementById('confirmVoidItem').disabled = true;
            });
        }
    })();
</script>
@endpush
