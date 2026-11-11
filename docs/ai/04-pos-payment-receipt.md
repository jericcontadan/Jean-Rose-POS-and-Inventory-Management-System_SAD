# Steps 2 and 3: POS, Payment and Receipt

Wireframes: screens 3 and 4. SRS FR-04, FR-05, FR-07. Access: CASHIER and OWNER.

## Step 2: POS screen (`GET /pos`)
Layout (tablet landscape): product area on the left, Order Details panel on the right.

Mockup: `docs/design/03-pos-cashier.png` and `04-payment-receipt.png` (HTML copies in `docs/design/screens/`).

**Header:** wordmark, rounded search box ("Search product", searches as you type or on Enter), Alerts button with a count (0 until Step 10), "Signed in as Cashier", Log out. The owner sees the nav tabs too.

**Category pills:** All, Skincare, Haircare, Cosmetics, Supplements (from the `category` table). No near-expiry filter tab (D23).

**Product cards:** image, name, price `₱120.00`, an "Add to order" button. Badge on the image: "Near expiry −10%" (yellow), "Low · 2 left" (orange), "Out of stock" (dark red). Out-of-stock cards are greyed with an "Unavailable" button (rule D6). Search matches product name or product number.

**Cart:** keep the order in the Laravel session on the server (not only in JavaScript), so totals are always computed by the server.
- `POST /pos/cart/add` (product_id), `POST /pos/cart/update` (product_id, quantity, for the − / + buttons), `POST /pos/cart/remove` (product_id), `POST /pos/cart/clear`. Small `fetch` calls that re-render the Order Details panel are best, so the page does not reload on every tap.
- Adding a product checks available stock (sum of unexpired batches). Show "Only N left" when the order would exceed stock.
- The cart stores product_id and quantity. The server allocates batches **earliest expiry first** (D3) and builds the preview lines. A line that spans a near-expiry batch and a normal batch shows as two lines.

**Order Details panel** (white card on the right, "N items" pill): each line shows image, name, a "near expiry −10%" pill or "₱150.00 each", − / xN / + buttons, Remove, and the line amount. Then Subtotal, "Near-expiry discount (auto)" in green with the products under it ("Serum 10%") and −12.00, then the pink Total Amount box (`₱558.00`). Buttons: "Cancel / Void" (outlined, clears the order after a confirm, D7) and "Pay now" (filled, disabled when the order is empty).

### Step 2 tests
- Discount applies only to batches within the near-expiry window; amounts match the money test in `01`.
- Expired batches are not offered. Out-of-stock products cannot be added.
- Allocation takes the earliest-expiry batch first and splits across batches.
- Cashier and owner can open `/pos`; guest cannot.

## Step 3: Pay Now (`POST /pos/pay`) and receipt
**Payment box** (opens from Pay now; mockup left card): TOTAL AMOUNT, Payment method "Cash" (only option, online payment is out of scope), Cash received * with quick buttons "Exact ₱558.00", "₱600", "₱1,000" (round up to the next ₱100 and ₱1,000 from the total), CHANGE (live preview in the browser). Rule shown: "Cash received must be equal to or more than the total." Buttons Cancel and Confirm payment.

**Server, in one `DB::transaction`:**
1. Re-read the cart, lock each needed `inventory` row with `lockForUpdate()`, re-allocate batches and recompute every amount. Ignore any totals sent by the browser.
2. If stock is now short, roll back and show "Stock changed. Please check the order." Nothing is saved.
3. Validate `cash_received ≥ total_amount` (decimal, 2 places).
4. Insert `sale`: `user_id` = current user, `subtotal`, `total_amount`, `discount_percent = 0` (D8), `sale_status = PAID`, `cash_received`, `change_amount`.
5. Insert one `sale_item` per allocated batch: `unit_price` copied from `product.unit_price`, `quantity`, `discount_percent`, `line_amount`.
6. For each batch: decrease `inventory.quantity_on_hand`, insert `inventory_history` (type SALE, negative `quantity_change`, current user), call `StockStatusService::refresh`.
7. `AuditLogger::log('SALE', 'SALES', 'S-0002', '2 items, total 558.00', $sale->sale_id)`.
8. Clear the cart. Redirect to `/receipts/{sale}`.
Use an idempotency token on the payment form so a double tap does not create two sales.

**Receipt (`GET /receipts/{sale}`):** "JEAN ROSE BEAUTY PRODUCTS TRADING", "SALES RECEIPT", Receipt no. `S-0002`, Date `11/6/2026 09:02`, Cashier (username), lines like "Serum x1 (−10%) 108.00", Subtotal, Discount, TOTAL, Cash, Change, "Thank you!". If the sale is CANCELLED, show a "VOIDED" stamp. Buttons PRINT RECEIPT (`window.print()` with a print stylesheet that hides menus and buttons) and NEW ORDER (back to `/pos`).
- A cashier can open receipts of any sale (needed for reprint). Confirm with the team if it should be only their own sales.

### Step 3 tests
- A paid sale writes 1 `sale`, the right `sale_item` rows, lowers stock, writes SALE history rows and 1 audit row, all with the wireframe numbers (570.00 / −12.00 / 558.00 / 600.00 / 42.00).
- Cash less than the total fails and saves nothing.
- If stock runs out between adding and paying, nothing is saved.
- A forced error in the middle rolls back everything (no partial sale).
- Later price changes do not change the stored `sale_item.unit_price`.
- `stock_status` becomes LOW STOCK or OUT OF STOCK when it should.
