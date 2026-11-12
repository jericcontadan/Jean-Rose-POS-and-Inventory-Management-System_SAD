{{--
    Receipt (screen 4). Mockup: docs/design/04-payment-receipt.png (right side).
    Needs $sale, $lines (from ReceiptController) and $discount. Only the receipt card is printed (see @media print in jeanrose.css).
--}}
@extends('layouts.app')

@section('title', 'Receipt '.$sale->receiptNumber())

@section('content')
    <div class="jr-receipt-page">
        <article class="jr-receipt" aria-label="Sales receipt {{ $sale->receiptNumber() }}">
            <div class="jr-receipt-shop">JEAN ROSE BEAUTY PRODUCTS<br>TRADING</div>
            <div class="jr-receipt-heading">SALES RECEIPT</div>

            @if ($sale->sale_status === 'CANCELLED')
                {{-- Voided sale: a big VOIDED stamp (it is printed too), with the reason and when it was voided. --}}
                <div class="jr-receipt-void" role="note">
                    <span class="jr-receipt-void-stamp">VOIDED</span>
                    <span>Reason: {{ $sale->void_reason }}</span>
                    @if ($sale->voided_at)
                        <span>Voided {{ $sale->voided_at->format('m/d/Y H:i') }}</span>
                    @endif
                </div>
            @endif

            <div class="jr-receipt-block">
                <div class="jr-receipt-row"><span>Receipt no.</span><span>{{ $sale->receiptNumber() }}</span></div>
                <div class="jr-receipt-row"><span>Date</span><span>{{ $sale->sale_datetime->format('m/d/Y H:i') }}</span></div>
                <div class="jr-receipt-row"><span>Cashier</span><span>{{ $sale->cashier->username }}</span></div>
            </div>

            <div class="jr-receipt-block">
                @foreach ($lines as $line)
                    <div class="jr-receipt-row"><span>{{ $line['label'] }}</span><span>{{ number_format($line['amount'], 2) }}</span></div>
                @endforeach
            </div>

            <div class="jr-receipt-block">
                <div class="jr-receipt-row"><span>Subtotal</span><span>{{ number_format($sale->subtotal, 2) }}</span></div>
                <div class="jr-receipt-row"><span>Discount</span><span>{{ $discount > 0 ? '-' : '' }}{{ number_format($discount, 2) }}</span></div>
                <div class="jr-receipt-row jr-receipt-total"><span>TOTAL</span><span>{{ number_format($sale->total_amount, 2) }}</span></div>
                <div class="jr-receipt-row"><span>Cash</span><span>{{ number_format($sale->cash_received, 2) }}</span></div>
                <div class="jr-receipt-row"><span>Change</span><span>{{ number_format($sale->change_amount, 2) }}</span></div>
            </div>

            <div class="jr-receipt-thanks">Thank you!</div>
        </article>

        <div class="jr-receipt-saved jr-no-print">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"></path></svg>
            Receipt is stored with the sale record
        </div>

        <div class="d-flex gap-3 jr-no-print">
            <button type="button" class="btn btn-outline-primary jr-receipt-btn" id="printReceipt">Print receipt</button>
            <a href="{{ route('pos.index') }}" class="btn btn-primary jr-receipt-btn">New order</a>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Browser print. The print CSS hides everything except the receipt card.
    document.getElementById('printReceipt').addEventListener('click', function () { window.print(); });
</script>
@endpush
