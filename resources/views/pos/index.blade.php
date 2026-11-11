{{--
    Point of Sale (screen 3). Mockup: docs/design/03-pos-cashier.png.
    Left: category pills + product cards. Right: Order Details (the cart kept in the session).
    All numbers come from PosController / CartService; this view only shows them.
--}}
@extends('layouts.app')

@section('title', 'Point of Sale')

{{-- Search box in the header bar. Typing filters the cards right away (script at the bottom).
     Enter still sends the form (GET), so the search also works without JavaScript. --}}
@section('header-search')
    <form method="GET" action="{{ route('pos.index') }}" class="jr-search" role="search" id="posSearchForm">
        <label for="q" class="visually-hidden">Search product</label>
        <svg class="jr-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path>
        </svg>
        <input id="q" name="q" type="search" class="form-control" placeholder="Search product"
               value="{{ $search }}" maxlength="100" autocomplete="off">
        {{-- Clear (x) button: empties the box and shows all products of the category again. --}}
        <button type="button" class="jr-search-clear" id="posSearchClear" aria-label="Clear search"
                @if ($search === '') hidden @endif>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg>
        </button>
        @if ($categoryId)
            <input type="hidden" name="category" value="{{ $categoryId }}">
        @endif
    </form>
@endsection

@section('content')
    @foreach ($order['notices'] as $notice)
        <div class="alert alert-warning" role="status">{{ $notice }}</div>
    @endforeach

    <div class="jr-pos">
        {{-- Left: categories and products --}}
        <section class="jr-pos-products" aria-label="Products">
            <nav class="jr-cat-pills" aria-label="Categories">
                <a href="{{ route('pos.index', array_filter(['q' => $search])) }}"
                   class="jr-cat-pill {{ $categoryId ? '' : 'active' }}"
                   @unless ($categoryId) aria-current="page" @endunless>All</a>
                @foreach ($categories as $category)
                    <a href="{{ route('pos.index', array_filter(['q' => $search, 'category' => $category->category_id])) }}"
                       class="jr-cat-pill {{ $categoryId == $category->category_id ? 'active' : '' }}"
                       @if ($categoryId == $category->category_id) aria-current="page" @endif>{{ $category->category_name }}</a>
                @endforeach
            </nav>

            {{-- Shown only when no card is visible (the script updates it while typing). --}}
            <p class="jr-empty" id="posNoProducts" @if ($cards->contains('matches', true)) hidden @endif>No products found.</p>

            <div class="jr-product-grid" id="posProductGrid">
                @foreach ($cards as $card)
                    @include('pos._product-card', $card)
                @endforeach
            </div>
        </section>

        {{-- Right: the current unpaid order --}}
        @include('pos._order-panel')
    </div>
@endsection

@push('scripts')
<script>
    // Live product search (plain JavaScript, no packages).
    // It only shows or hides cards that are already on the page; the cart is not touched.
    (function () {
        const input = document.getElementById('q');
        const clearBtn = document.getElementById('posSearchClear');
        const cards = document.querySelectorAll('#posProductGrid .jr-product-card');
        const noProducts = document.getElementById('posNoProducts');
        const pills = document.querySelectorAll('.jr-cat-pill');

        function applySearch() {
            const text = input.value.trim().toLowerCase();
            let shown = 0;

            // Show a card when its name or product number contains the text (case ignored).
            cards.forEach(function (card) {
                const match = text === '' || card.dataset.search.includes(text);
                card.hidden = !match;
                if (match) shown++;
            });

            noProducts.hidden = shown > 0;
            clearBtn.hidden = input.value === '';

            // Keep the address bar and the category pills in step with the box, so a category tap
            // or a cart button (which comes back to this address) keeps the same search.
            const url = new URL(window.location.href);
            text === '' ? url.searchParams.delete('q') : url.searchParams.set('q', input.value.trim());
            history.replaceState(null, '', url);

            pills.forEach(function (pill) {
                const pillUrl = new URL(pill.href);
                text === '' ? pillUrl.searchParams.delete('q') : pillUrl.searchParams.set('q', input.value.trim());
                pill.href = pillUrl;
            });
        }

        input.addEventListener('input', applySearch); // every key, paste, and the browser's own clear

        clearBtn.addEventListener('click', function () {
            input.value = '';
            applySearch();
            input.focus();
        });
    })();
</script>
@endpush
