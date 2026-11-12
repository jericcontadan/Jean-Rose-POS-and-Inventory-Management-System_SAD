{{--
    Batch fields: "First batch" on Add product and "Add new batch" on Edit product (screen 12).
    Needs: $bag, the error bag to read ('default' on Add product, 'batch' on Edit product).
--}}
<div class="jr-edit-row">
    <div class="jr-edit-field">
        <label for="batch_number" class="jr-edit-label">Batch number *</label>
        <input id="batch_number" name="batch_number" type="text" maxlength="50" required autocomplete="off"
               class="form-control @error('batch_number', $bag) is-invalid @enderror"
               value="{{ old('batch_number') }}">
        @error('batch_number', $bag)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="jr-edit-field">
        <label for="expiry_date" class="jr-edit-label">Expiry date *</label>
        <input id="expiry_date" name="expiry_date" type="date" required min="{{ now()->toDateString() }}"
               class="form-control @error('expiry_date', $bag) is-invalid @enderror"
               value="{{ old('expiry_date') }}">
        @error('expiry_date', $bag)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="jr-edit-row">
    <div class="jr-edit-field">
        <label for="quantity" class="jr-edit-label">Quantity received *</label>
        <input id="quantity" name="quantity" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="off"
               class="form-control @error('quantity', $bag) is-invalid @enderror"
               value="{{ old('quantity') }}">
        @error('quantity', $bag)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="jr-edit-field">
        <label for="reorder_level" class="jr-edit-label">Reorder level *</label>
        <input id="reorder_level" name="reorder_level" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="off"
               class="form-control @error('reorder_level', $bag) is-invalid @enderror"
               value="{{ old('reorder_level') }}">
        @error('reorder_level', $bag)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
