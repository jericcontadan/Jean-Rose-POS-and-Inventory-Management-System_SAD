{{--
    Update price (screen 10), OWNER only. Mockup: docs/design/06-stock-price-product-import.png ("Update price" card),
    built as its own page with the card centered. Only "New unit price" is sent.
    Saving changes product.unit_price only (InventoryService::changePrice); past sales keep their own price.
--}}
@extends('layouts.app')

@section('title', 'Update price')

@section('content')
    <div class="jr-form-page">
        <form method="POST" action="{{ route('products.price.update', $product) }}" class="card jr-edit-card" novalidate>
            @csrf
            @method('PUT')

            <h1 class="jr-edit-title">Update price</h1>

            <div class="jr-edit-field">
                <span class="jr-edit-label">Product</span>
                <div class="jr-readonly-box">{{ $product->product_name }}</div>
            </div>

            <div class="jr-edit-row">
                <div class="jr-edit-field">
                    <span class="jr-edit-label">Current price</span>
                    <div class="jr-readonly-box jr-readonly-big">₱{{ number_format((float) $product->unit_price, 2) }}</div>
                </div>
                <div class="jr-edit-field">
                    <label for="unit_price" class="jr-edit-label">New unit price *</label>
                    <input id="unit_price" name="unit_price" type="text" inputmode="decimal" maxlength="13"
                           autocomplete="off" required autofocus placeholder="0.00"
                           class="form-control jr-edit-input @error('unit_price') is-invalid @enderror"
                           value="{{ old('unit_price') }}">
                    @error('unit_price')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="jr-form-note">
                <p class="mb-1">The old and new price are saved in the audit log.</p>
                <p class="mb-0">Past sales keep the price they were sold at.</p>
            </div>

            <div class="jr-edit-buttons">
                <a href="{{ route('inventory.index') }}" class="btn btn-outline-primary jr-edit-cancel">Cancel</a>
                <button type="submit" class="btn btn-primary jr-edit-save">Save price</button>
            </div>
        </form>
    </div>
@endsection
