{{--
    Remove stock, OWNER only. Same card as Update stock (docs/design/06-stock-price-product-import.png).
    Takes pieces OUT of one batch, for example expired or damaged pieces. Product, batch and current stock are read only;
    only "Quantity to remove" and the reason are sent. Saving adds an ADJUSTMENT row in inventory history
    and an INVENTORY_UPDATE row in the audit log with the reason (InventoryService::removeStock).
--}}
@extends('layouts.app')

@section('title', 'Remove stock')

@section('content')
    <div class="jr-form-page">
        <form method="POST" action="{{ route('inventory.remove.update', $inventory) }}" class="card jr-edit-card" novalidate>
            @csrf
            @method('PUT')

            <h1 class="jr-edit-title">Remove stock</h1>

            <div class="jr-edit-field">
                <span class="jr-edit-label">Product</span>
                <div class="jr-readonly-box">{{ $inventory->batch->product->product_name }}</div>
            </div>

            <div class="jr-edit-field">
                <span class="jr-edit-label">Batch</span>
                <div class="jr-readonly-box">
                    {{ $inventory->batch->batch_number }} (expires {{ $inventory->batch->expiry_date->format('m/d/Y') }})
                </div>
            </div>

            <div class="jr-edit-row">
                <div class="jr-edit-field">
                    <span class="jr-edit-label">Current stock</span>
                    <div class="jr-readonly-box jr-readonly-big" id="currentStock" data-current="{{ $inventory->quantity_on_hand }}">
                        {{ $inventory->quantity_on_hand }}
                    </div>
                </div>
                <div class="jr-edit-field">
                    <label for="quantity" class="jr-edit-label">Quantity to remove *</label>
                    <input id="quantity" name="quantity" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                           autocomplete="off" required autofocus
                           class="form-control jr-edit-input @error('quantity') is-invalid @enderror"
                           value="{{ old('quantity') }}">
                    @error('quantity')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="jr-edit-field">
                <label for="reason" class="jr-edit-label">Reason *</label>
                <select id="reason" name="reason" required class="form-select @error('reason') is-invalid @enderror">
                    <option value="">Choose a reason</option>
                    @foreach ($reasons as $reason)
                        <option value="{{ $reason }}" @selected(old('reason') === $reason)>{{ $reason }}</option>
                    @endforeach
                </select>
                @error('reason')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- Live preview: current stock - quantity to remove (script below). The server checks it again on Save. --}}
            <div class="jr-new-stock" aria-live="polite">
                <span class="jr-new-stock-label">NEW STOCK</span>
                <span class="jr-new-stock-value" id="newStock">&mdash;</span>
            </div>

            <p class="jr-form-note">Saved as an ADJUSTMENT in inventory history, with the reason in the audit log.</p>

            <div class="jr-edit-buttons">
                <a href="{{ route('inventory.index') }}" class="btn btn-outline-primary jr-edit-cancel">Cancel</a>
                <button type="submit" class="btn btn-primary jr-edit-save">Remove stock</button>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
<script>
    // New stock preview: current stock - the quantity typed, only for a whole number from 1 to the current stock.
    (function () {
        const current = parseInt(document.getElementById('currentStock').dataset.current, 10);
        const input = document.getElementById('quantity');
        const output = document.getElementById('newStock');

        function showNewStock() {
            const text = input.value.trim();
            const quantity = /^[0-9]+$/.test(text) ? parseInt(text, 10) : 0;
            output.textContent = (quantity >= 1 && quantity <= current) ? String(current - quantity) : '—';
        }

        input.addEventListener('input', showNewStock);
        showNewStock(); // also after a refused save (the old value is filled in again)
    })();
</script>
@endpush
