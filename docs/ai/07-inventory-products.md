# Steps 4, 5 and 6: Inventory, Add or Edit Product, Update Stock and Price

Wireframes: screens 9, 10 and 12. SRS FR-06, FR-07, FR-09. Access: OWNER only (middleware `owner`).

Mockup: `docs/design/05-inventory-owner.png` (list) and `06-stock-price-product-import.png` (forms). Forms are built as separate pages with the card centered (D22).

## Step 4: Inventory (`GET /inventory`)
- Title "Inventory" with buttons on the right: "+ Add product" (filled), "Import Excel" (Step 13), "Export Excel" (Step 12). Disable Import and Export until their steps are built.
- Three summary cards: LOW STOCK, OUT OF STOCK, NEAR EXPIRY, each with a count and the first item ("Vitamins · 2 left (reorder at 5)").
- Search box ("Search product") and Clear. Search matches product name, product number, batch number or supplier.
- Table, one row per batch (D11): List of products (bold name, batch number small under it) | Price | Stocks | Supplier | Status | Expiration date | Actions.
  Example: Serum | ₱120.00 | 10 | SY TRADING | NEAR EXPIRY | 11/25/2026
- Status is `inventory.stock_status`, shown as a colored pill (OKAY shown as "OK"). An expired batch shows "Expired" in red next to the date.
- Actions per row: "Update stock" (filled pink when LOW STOCK or OUT OF STOCK, outlined otherwise), "Update price", and a trash icon button for Delete row. No row selection and no Close button (D10).

### Step 4 tests
- Owner sees all batches with correct status; cashier gets 403.
- Search narrows the list.

## Step 5: Add or Edit Product (`/products/create`, `/products/{id}/edit`), Delete Row
**Form fields:** PRODUCT NUMBER * (unique, error "This product number already exists."), PRODUCT NAME *, CATEGORY * (dropdown), SUPPLIER * (dropdown), COST PRICE *, UNIT PRICE *, PRODUCT IMAGE (UPLOAD IMAGE, optional, jpg or png up to 2 MB). FIRST BATCH (create only): BATCH NUMBER *, EXPIRY DATE *, QUANTITY RECEIVED *, REORDER LEVEL *. Buttons CANCEL and SAVE PRODUCT. Note "* required. Prices and quantities must be numbers."

**Validation:** prices numeric, ≥ 0, max 2 decimals; unit price should not be lower than cost price (warn, then allow on confirm); quantities whole numbers ≥ 0; expiry date a valid date; batch number unique for that product.

**Create, in one transaction:** insert `product` (image saved with `Storage::disk(config('filesystems.default'))` under `products/`, or the placeholder D13), insert `product_batch` (`date_received = today`), insert `inventory` (`quantity_on_hand` = quantity received), insert `inventory_history` (RESTOCK, + quantity), refresh status, audit `INVENTORY_UPDATE` / INVENTORY / `P-0001` / "Added product Serum, batch B-2026-11, qty 10".

**Edit:** same fields except the first batch. If the unit price changes, log it exactly like Step 6. Other changes: audit "Edited product …". Edit page also has an **Add batch** section (batch number, expiry date, quantity received, reorder level) for a new delivery with a different expiry. Same insert steps as the first batch.

**Delete Row** (`DELETE /inventory/{id}`): confirm with a Bootstrap modal ("Delete batch B-2026-11 of Serum?"). Allowed only by rule D12; otherwise show the message. In a transaction delete the history rows of that batch's first RESTOCK only if no other history exists, then inventory, then batch, then the product if it has no batches left. Audit "Deleted batch …". If the rule blocks it, change nothing.

### Step 5 tests
- Create writes product, batch, inventory, one RESTOCK history row and one audit row.
- Duplicate product number is rejected with the wireframe message.
- No image stores the placeholder path.
- Delete is blocked for a batch with sales; allowed for an unused batch.

## Step 6: Update Stock and Price
**Update Stocks** (`GET/PUT /inventory/{id}/stock`): PRODUCT (read-only), BATCH * (dropdown of that product's batches, "B-2026-04 (expires 06/01/2027)"), CURRENT STOCK, QUANTITY TO ADD * (whole number 1 to 100000), NEW STOCK (live preview), note "Saved as a RESTOCK in inventory history.", CANCEL and SAVE STOCK.
- Transaction: lock inventory row, add to `quantity_on_hand` and to `product_batch.quantity_in_batch` (D19), insert `inventory_history` RESTOCK, refresh status, audit `INVENTORY_UPDATE` / INVENTORY / `P-0003` / "Restock B-2026-04 +20 (2 to 22)".
- Stock can only go up here. Corrections that lower stock are out of this step (ask before adding).

**Update Price** (`GET/PUT /products/{id}/price`): PRODUCT, CURRENT PRICE (read-only), NEW UNIT PRICE *, notes "The old and new price are saved in the audit log." and "Past sales keep the price they were sold at.", CANCEL and SAVE PRICE.
- Transaction: update `product.unit_price`, audit `INVENTORY_UPDATE` / INVENTORY / `P-0001` / "Price 120.00 to 125.00". If the price is unchanged, save nothing.

### Step 6 tests
- Restock adds to both quantity columns, writes RESTOCK history and audit with old and new stock.
- Status goes from LOW STOCK to OKAY after a big enough restock.
- Price change writes the audit row with old and new price; existing `sale_item.unit_price` values do not change.
- Cashier gets 403 on all routes.
