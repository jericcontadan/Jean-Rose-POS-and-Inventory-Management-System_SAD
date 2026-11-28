{{--
    One product card. Needs: $product, $available, $status (from BatchPickerService::productStatus), $matches, $discountText.
    Only ONE badge per card, in rule order: Expired > Out of stock > Near expiry > Low. OKAY shows no badge.
    Expired and Out of stock cards are both "Unavailable" (no Add to order).
    data-search holds the lower-case name and product number for the live search; non-matching cards start hidden.
    Fixed layout so every card lines up: image area (fixed height) > name (always 2 lines, cut with … ; full name in title)
    > price on its own line > button pinned to the bottom. No d-flex class: it would override the hidden attribute.
--}}
@php($isOut = in_array($status, ['OUT OF STOCK', 'EXPIRED']))

<article class="jr-product-card h-100 flex-column {{ $isOut ? 'is-out' : '' }}"
         data-search="{{ mb_strtolower($product->product_name.' '.$product->product_number) }}"
         @unless ($matches) hidden @endunless>
    <div class="jr-product-img">
        @include('pos._product-image', ['product' => $product, 'size' => 72])

        @if ($status === 'EXPIRED')
            <span class="jr-card-badge jr-badge-expired">Expired</span>
        @elseif ($isOut)
            <span class="jr-card-badge jr-badge-out">Out of stock</span>
        @elseif ($status === 'NEAR EXPIRY')
            <span class="jr-card-badge jr-badge-near">Near expiry −{{ $discountText }}</span>
        @elseif ($status === 'LOW STOCK')
            <span class="jr-card-badge jr-badge-low">Low · {{ $available }} left</span>
        @endif
    </div>

    <div class="jr-product-info">
        <span class="jr-product-name" title="{{ $product->product_name }}">{{ $product->product_name }}</span>
        <span class="jr-product-price">₱{{ number_format($product->unit_price, 2) }}</span>
    </div>

    <div class="jr-product-action">
        @if ($isOut)
            <button type="button" class="btn jr-btn-unavailable" disabled>Unavailable</button>
        @else
            <form method="POST" action="{{ route('pos.cart.add', $product) }}">
                @csrf
                <button type="submit" class="btn btn-primary w-100">Add to order</button>
            </form>
        @endif
    </div>
</article>
