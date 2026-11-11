# Steps 7 and 8: Sales Records and Void a Sale

Wireframes: screens 7 and 8. SRS FR-05, FR-08, FR-09. Access: OWNER only (middleware `owner`).

Mockup: `docs/design/07-sales-records-void.png`. The void form is a card on the right of the same page (not a separate page). Note: the mockup's sample rows and the sample void card show different sales; that is only sample data.

## Step 7: Sales Records (`GET /sales`)
Mockup layout: title "Sales Records", then Date picker, "Export to Excel" (outlined) and "Close" (filled) on the right. Table with an Action column. "Total sales ₱366.00" box under the table.
- Date selector at the top (default today, Asia/Manila). Filter on `sale.sale_datetime` (uses `idx_sale_datetime`).
- Table, one row per `sale_item`, grouped by sale with the receipt number `S-0002` and time:
  Date | Category | Product | Quantity | Price | Discount | Total Amount
  Example: 11/6/2026 | Skincare | Serum | 2 | 120 | 10% | 216.00
- Price is `sale_item.unit_price` (the price at the time of sale), never the current product price.
- CANCELLED sales are shown greyed with "VOIDED" and the reason, and are not counted in the day total.
- Footer: number of paid sales and total sales for the day.
- A "Void" button in the Action column, once per PAID sale (D21). It voids the whole sale, not one item. In Step 7 it is disabled; Step 8 makes it open the void card. "Export to Excel" stays disabled until Step 12. "Close" goes back to POS (D10).
- Each sale has a link to its receipt `/receipts/{sale}` for reprinting.
- Paginate if more than 50 sales on a day.

### Step 7 tests
- Owner sees only the chosen date's sales; cashier gets 403.
- Totals match stored `line_amount` values; voided sales are excluded from the day total.

## Step 8: Void a Sale (`GET /sales?date=…&void={id}` shows the card, `POST /sales/{id}/void` saves)
- The Void button on a row reloads Sales Records with the void card on the right (the selected sale's Void button turns filled dark red).
- Card: "Void sale S-0002", SELECTED SALE box ("11/6/2026 09:02", "Serum x1, Makeup x3", "Total 558.00" in pink), Reason * (textarea, required, 5 to 255 characters, placeholder "Example: wrong item entered"), the info note "Stock will be returned to inventory. This is saved in the audit log.", buttons Cancel (closes the card) and Confirm void (dark red).
- Server, in one `DB::transaction`:
  1. Lock the sale row. Only a PAID sale can be voided. A CANCELLED sale returns "This sale was already voided."
  2. Set `sale_status = CANCELLED`, `void_reason`, `voided_at = now()`, `voided_by = current user`.
  3. For each `sale_item`: lock its `inventory` row, add the quantity back to `quantity_on_hand`, insert `inventory_history` (type VOID, positive `quantity_change`), call `StockStatusService::refresh`.
  4. `AuditLogger::log('VOID', 'SALES', 'S-0002', 'Sale cancelled: <reason>', $sale->sale_id)`.
- Then redirect to `/sales` for that date with "Sale S-0002 was voided."
- Do not delete the sale or its items. Voided sales stay for the record.

### Step 8 tests
- Void returns every batch's stock, writes VOID history rows, sets all void columns, writes one audit row.
- Reason is required.
- Voiding twice fails the second time and changes nothing.
- Cashier gets 403 on both routes.
- The receipt of a voided sale shows "VOIDED".
