{{--
    The product fields, shared by Add product and Edit product (screen 12).
    Needs: $categories, $suppliers, and $product (null on Add product).
    After a failed save, old() puts back what the owner typed; otherwise Edit shows the saved values.
--}}
@php($product = $product ?? null)

<div class="jr-edit-field">
    <label for="product_number" class="jr-edit-label">Product number *</label>
    <input id="product_number" name="product_number" type="text" maxlength="60" required autocomplete="off"
           class="form-control @error('product_number') is-invalid @enderror"
           value="{{ old('product_number', $product?->product_number) }}">
    @error('product_number')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="jr-edit-field">
    <label for="product_name" class="jr-edit-label">Product name *</label>
    <input id="product_name" name="product_name" type="text" maxlength="150" required autocomplete="off"
           class="form-control @error('product_name') is-invalid @enderror"
           value="{{ old('product_name', $product?->product_name) }}">
    @error('product_name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="jr-edit-row">
    <div class="jr-edit-field">
        <label for="category_id" class="jr-edit-label">Category *</label>
        <select id="category_id" name="category_id" required class="form-select @error('category_id') is-invalid @enderror">
            <option value="">Choose…</option>
            @foreach ($categories as $category)
                <option value="{{ $category->category_id }}" @selected((string) old('category_id', $product?->category_id) === (string) $category->category_id)>
                    {{ $category->category_name }}
                </option>
            @endforeach
        </select>
        @error('category_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="jr-edit-field">
        <label for="supplier_id" class="jr-edit-label">Supplier *</label>
        <select id="supplier_id" name="supplier_id" required class="form-select @error('supplier_id') is-invalid @enderror">
            <option value="">Choose…</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->supplier_id }}" @selected((string) old('supplier_id', $product?->supplier_id) === (string) $supplier->supplier_id)>
                    {{ $supplier->supplier_name }}
                </option>
            @endforeach
        </select>
        @error('supplier_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="jr-edit-row">
    <div class="jr-edit-field">
        <label for="cost_price" class="jr-edit-label">Cost price *</label>
        <input id="cost_price" name="cost_price" type="text" inputmode="decimal" maxlength="13" required autocomplete="off" placeholder="0.00"
               class="form-control @error('cost_price') is-invalid @enderror"
               value="{{ old('cost_price', $product?->cost_price) }}">
        @error('cost_price')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="jr-edit-field">
        <label for="unit_price" class="jr-edit-label">Unit price *</label>
        <input id="unit_price" name="unit_price" type="text" inputmode="decimal" maxlength="13" required autocomplete="off" placeholder="0.00"
               class="form-control @error('unit_price') is-invalid @enderror"
               value="{{ old('unit_price', $product?->unit_price) }}">
        @error('unit_price')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

{{-- Product image: the real file input is hidden; the "Upload image" button is its label (works by tap). --}}
<div class="jr-edit-field">
    <div class="jr-image-box @error('image') is-invalid @enderror">
        <div class="jr-image-thumb">
            @if ($product)
                @include('pos._product-image', ['product' => $product, 'size' => 48])
            @else
                @include('pos._product-image', ['product' => new \App\Models\Product(), 'size' => 34])
            @endif
        </div>
        <div class="jr-image-text">
            <span class="jr-edit-label">Product image</span>
            <span class="jr-image-name" id="imageName">{{ $product ? 'Keep the current image' : 'Optional · JPG or PNG, max 2 MB' }}</span>
        </div>
        <label for="image" class="btn btn-outline-primary jr-image-btn">Upload image</label>
        <input id="image" name="image" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="visually-hidden">
    </div>
    @error('image')
        <div class="jr-field-error">{{ $message }}</div>
    @enderror
</div>

@push('scripts')
<script>
    // Show the chosen file name in the image box (plain JavaScript).
    document.getElementById('image').addEventListener('change', function () {
        if (this.files.length > 0) {
            document.getElementById('imageName').textContent = this.files[0].name;
        }
    });
</script>
@endpush
