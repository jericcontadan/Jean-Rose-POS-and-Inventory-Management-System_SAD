{{--
    Add product (screen 12), OWNER only. Mockup: docs/design/06-stock-price-product-import.png ("Add product" card),
    built as its own page with the card centered.
    Saving inserts the product, its first batch and the batch's stock row in one transaction
    (InventoryService::addProduct). No image = images/placeholder.png.
--}}
@extends('layouts.app')

@section('title', 'Add product')

@section('content')
    <div class="jr-form-page">
        <form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data"
              class="card jr-edit-card jr-product-form" novalidate>
            @csrf

            <h1 class="jr-edit-title">Add product</h1>

            @include('products._fields', ['product' => null])

            <div class="jr-batch-section">
                <h2 class="jr-section-title">First batch</h2>
                @include('products._batch-fields', ['bag' => 'default'])
            </div>

            <p class="jr-form-note">* required. Prices and quantities must be numbers.</p>

            <div class="jr-edit-buttons">
                <a href="{{ route('inventory.index') }}" class="btn btn-outline-primary jr-edit-cancel">Cancel</a>
                <button type="submit" class="btn btn-primary jr-edit-save">Save product</button>
            </div>
        </form>
    </div>
@endsection
