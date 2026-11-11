{{--
    Product picture: product.image_path if the file is in public/, otherwise a pink placeholder icon.
    Needs: $product, $size (pixels).
--}}
@if ($product->image_path && is_file(public_path($product->image_path)))
    <img src="{{ asset($product->image_path) }}" alt="" width="{{ $size }}" height="{{ $size }}" class="jr-product-photo">
@else
    <svg class="jr-placeholder-icon" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 48 48" aria-hidden="true">
        <rect class="jr-ph-light" x="16" y="18" width="16" height="24" rx="4"></rect>
        <rect class="jr-ph-dark" x="20" y="6" width="8" height="12" rx="2"></rect>
        <rect class="jr-ph-dark" x="22" y="2" width="4" height="6" rx="1"></rect>
    </svg>
@endif
