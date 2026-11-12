{{--
    Edit product (screen 12), OWNER only. Same card as Add product (mockup 06), without the batch fields.
    A second card, "Add new batch", adds a new delivery with its own batch number and expiry date.
    Two separate forms: Save changes -> InventoryService::editProduct, Add batch -> InventoryService::addBatch.
--}}
@extends('layouts.app')

@section('title', 'Edit product')

@section('content')
    <div class="jr-form-page jr-form-page-two">
        {{-- Card 1: product fields --}}
        <form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data"
              class="card jr-edit-card jr-product-form" novalidate>
            @csrf
            @method('PUT')

            <h1 class="jr-edit-title">Edit product</h1>

            @include('products._fields', ['product' => $product])

            <div class="jr-form-note">
                <p class="mb-1">* required. Prices must be numbers.</p>
                <p class="mb-0">A new unit price is saved in the audit log. Past sales keep the price they were sold at.</p>
            </div>

            <div class="jr-edit-buttons">
                <a href="{{ route('inventory.index') }}" class="btn btn-outline-primary jr-edit-cancel">Cancel</a>
                <button type="submit" class="btn btn-primary jr-edit-save">Save changes</button>
            </div>
        </form>

        {{-- Card 2: the batches this product has, and the Add new batch form --}}
        <form method="POST" action="{{ route('products.batches.store', $product) }}" class="card jr-edit-card jr-product-form" novalidate>
            @csrf

            <h2 class="jr-edit-title">Add new batch</h2>
            <p class="jr-form-note">For a new delivery with a different expiry date. To add pieces to a batch you already have, use Update stock.</p>

            <div class="jr-batch-list">
                <span class="jr-edit-label">Current batches</span>
                @forelse ($batches as $batch)
                    <div class="jr-batch-list-row">
                        <span class="fw-bold">{{ $batch->batch_number }}</span>
                        <span>expires {{ $batch->expiry_date->format('m/d/Y') }}</span>
                        <span class="text-end">{{ $batch->inventory?->quantity_on_hand ?? 0 }} pcs</span>
                    </div>
                @empty
                    <div class="jr-batch-list-row">No batches yet.</div>
                @endforelse
            </div>

            <div class="jr-batch-section">
                @include('products._batch-fields', ['bag' => 'batch'])
            </div>

            <div class="jr-edit-buttons">
                <button type="submit" class="btn btn-primary jr-edit-save">Add batch</button>
            </div>
        </form>
    </div>
@endsection
