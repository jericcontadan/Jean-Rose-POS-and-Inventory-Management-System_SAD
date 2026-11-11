{{--
    Order Details panel. Needs $order from CartService::summary():
    lines, discounts, item_count, subtotal, total. The browser never sends prices; it only presses buttons.
--}}
<aside class="jr-order-panel" aria-labelledby="orderTitle">
    <div class="d-flex justify-content-between align-items-center">
        <h2 id="orderTitle" class="jr-order-title">Order Details</h2>
        <span class="jr-chip">{{ $order['item_count'] }} {{ $order['item_count'] === 1 ? 'item' : 'items' }}</span>
    </div>

    @if (empty($order['lines']))
        <p class="jr-empty mb-0">No items yet. Tap "Add to order" on a product.</p>
    @else
        <div class="jr-order-lines">
            @foreach ($order['lines'] as $line)
                @php($name = $line['product']->product_name)
                <div class="jr-order-line">
                    <div class="d-flex align-items-center gap-3">
                        <div class="jr-order-thumb">
                            @include('pos._product-image', ['product' => $line['product'], 'size' => 34])
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="jr-order-name">{{ $name }}</div>
                            @if ($line['near_expiry'])
                                <span class="jr-badge-near jr-badge-sm">near expiry −{{ $line['discount_text'] }}</span>
                            @else
                                <div class="jr-order-each">₱{{ number_format($line['unit_price'], 2) }} each</div>
                            @endif
                        </div>
                        <div class="jr-order-amount">{{ number_format($line['line_total'], 2) }}</div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-1">
                            <form method="POST" action="{{ route('pos.cart.decrease', $line['product']) }}">
                                @csrf
                                <button type="submit" class="jr-qty-btn" aria-label="Decrease {{ $name }} quantity">−</button>
                            </form>
                            <span class="jr-qty" aria-label="Quantity">x{{ $line['quantity'] }}</span>
                            <form method="POST" action="{{ route('pos.cart.increase', $line['product']) }}">
                                @csrf
                                {{-- Disabled when the line already uses all sellable stock (the server checks too). --}}
                                <button type="submit" class="jr-qty-btn" aria-label="Increase {{ $name }} quantity"
                                        @disabled($line['quantity'] >= $line['available'])>+</button>
                            </form>
                        </div>
                        <form method="POST" action="{{ route('pos.cart.remove', $line['product']) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="jr-remove-btn">Remove</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="jr-order-sums">
        <div class="d-flex justify-content-between">
            <span>Subtotal</span>
            <span class="fw-bold">{{ number_format($order['subtotal'], 2) }}</span>
        </div>
        {{-- Shown only when there is a real discount, so it never reads "−0.00". --}}
        @if (! empty($order['discounts']) && $order['discount_total'] > 0)
            <div class="jr-discount-line d-flex justify-content-between">
                <span>
                    Near-expiry discount (auto)
                    @foreach ($order['discounts'] as $discount)
                        <br><small>{{ $discount['label'] }}</small>
                    @endforeach
                </span>
                <span class="fw-bold">−{{ number_format($order['discount_total'], 2) }}</span>
            </div>
        @endif
    </div>

    <div class="jr-total-box d-flex justify-content-between align-items-center">
        <span class="jr-total-label">Total Amount</span>
        <span class="jr-total-amount" data-testid="order-total">₱{{ number_format($order['total'], 2) }}</span>
    </div>

    <div class="d-flex gap-3">
        <button type="button" class="btn jr-btn-void" data-bs-toggle="modal" data-bs-target="#cancelOrderModal"
                @disabled(empty($order['lines']))>Cancel / Void</button>

        {{-- Opens the payment box (pos/_payment-modal). Nothing is saved until "Confirm payment". --}}
        <button type="button" class="btn btn-primary jr-btn-pay" data-bs-toggle="modal" data-bs-target="#paymentModal"
                @disabled(empty($order['lines']))>Pay now</button>
    </div>
</aside>

@if (! empty($order['lines']))
    @include('pos._payment-modal')
@endif

{{-- Confirm box for Cancel / Void (tap to open, no hover). It only clears this unpaid order. --}}
<div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-labelledby="cancelOrderTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content jr-modal">
            <div class="modal-header border-0">
                <h2 class="modal-title fs-4" id="cancelOrderTitle">Cancel this order?</h2>
            </div>
            <div class="modal-body">
                All items will be removed from the order. Nothing has been paid or saved yet.
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Keep order</button>
                <form method="POST" action="{{ route('pos.cart.clear') }}" class="m-0">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Yes, cancel order</button>
                </form>
            </div>
        </div>
    </div>
</div>
