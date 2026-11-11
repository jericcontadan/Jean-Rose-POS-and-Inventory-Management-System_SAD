{{--
    One product card. Needs: $product, $available, $status (from BatchPickerService::productStatus), $matches, $discountText.
    Only ONE badge per card, in rule order: Out of stock > Near expiry > Low. OKAY shows no badge.
    data-search holds the lower-case name and product number for the live search; non-matching cards start hidden.
--}}
@php($isOut = $status === 'OUT OF STOCK')

<article class="jr-product-card {{ $isOut ? 'is-out' : '' }}"
         data-search="{{ mb_strtolower($product->product_name.' '.$product->product_number) }}"
         @unless ($matches) hidden @endunless>
    <div class="jr-product-img">
        @include('pos._product-image', ['product' => $product, 'size' => 72])

        @if ($isOut)
            <span class="jr-card-badge jr-badge-out">Out of stock</span>
        @elseif ($status === 'NEAR EXPIRY')
            <span class="jr-card-badge jr-badge-near">Near expiry −{{ $discountText }}</span>
        @elseif ($status === 'LOW STOCK')
            <span class="jr-card-badge jr-badge-low">Low · {{ $available }} left</span>
        @endif
    </div>

    <div class="jr-product-info">
        <span class="jr-product-name">{{ $product->product_name }}</span>
        <span class="jr-product-price">₱{{ number_format($product->unit_price, 2) }}</span>
    </div>

    @if ($isOut)
        <button type="button" class="btn jr-btn-unavailable" disabled>Unavailable</button>
    @else
        <form method="POST" action="{{ route('pos.cart.add', $product) }}">
            @csrf
            <button type="submit" class="btn btn-primary w-100">Add to order</button>
        </form>
    @endif
</article>
