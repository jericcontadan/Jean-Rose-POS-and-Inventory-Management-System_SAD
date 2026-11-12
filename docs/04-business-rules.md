# 04 - Business rules (follow exactly)

Settings live in `config/pos.php`, read from `.env` (defaults shown):
`NEAR_EXPIRY_DAYS=30`, `NEAR_EXPIRY_DISCOUNT_PERCENT=10`. Both are placeholders until the owner confirms.

## Stock status (one value per inventory row, stored in `inventory.stock_status`)
Check in this order and use the first match:
1. `quantity_on_hand = 0` -> OUT OF STOCK
2. batch `expiry_date` is within NEAR_EXPIRY_DAYS from today (and not yet expired) -> NEAR EXPIRY
3. `quantity_on_hand <= reorder_level` -> LOW STOCK
4. otherwise -> OKAY
Refresh `stock_status` after every sale, void, restock and import, and run a daily scheduled command at 06:00
(`Asia/Manila`) because near-expiry changes with time. Expired batches (`expiry_date < today`) are not sold.

## Choosing the batch to sell (FEFO)
For a product, sell from the batch with the earliest `expiry_date` that has stock and is not expired.
If one sale needs more than one batch, split it into several `sale_item` rows (one per batch).

## Pay Now (one `DB::transaction()`, all or nothing)
1. Validate cart: each product has enough stock in sellable batches; quantities are whole numbers > 0.
2. Calculate each line: `unit_price` from `product.unit_price`; `discount_percent` = NEAR_EXPIRY_DISCOUNT_PERCENT if the batch is
   NEAR EXPIRY, otherwise 0; `line_amount = quantity * unit_price * (1 - discount_percent/100)`.
3. Insert `sale`: `subtotal = sum(quantity * unit_price)`, `total_amount = sum(line_amount)`, `discount_percent = 0`
   (sale-level discount is not used), `sale_status = 'PAID'`, `user_id` = logged-in user,
   `cash_received` = cash from the cashier, `change_amount = cash_received - total_amount`.
4. Insert `sale_item` rows (copy `unit_price` so later price changes do not alter this sale).
5. For each batch sold: lower `inventory.quantity_on_hand`, refresh `stock_status`, insert `inventory_history`
   (`quantity_change` negative, type SALE), then sync alerts (see Alerts).
6. Insert `audit_log`: action SALE, module SALES, `sale_id`, `transaction_reference = 'S-0001'` style,
   `details = 'Items 2, total 558.00, cash 600.00, change 42.00'`.
7. Cash received must be >= total. Round money to 2 decimals. If any step fails, roll back everything and show an error.

## Void a recorded sale (OWNER only, one `DB::transaction()`)
- Only sales with `sale_status = 'PAID'`. A reason is required.
- Set `sale_status = 'CANCELLED'`, `void_reason` = the reason, `voided_at = now()`, `voided_by` = the owner's `user_id`. Return each `sale_item.quantity` to the same batch's `inventory.quantity_on_hand`,
  refresh `stock_status`, insert `inventory_history` (positive `quantity_change`, type VOID), then sync alerts.
- Insert `audit_log`: action VOID, module SALES, `sale_id`, `details = 'Reason: ...'`. Do not delete the sale.

## Restock and price change (OWNER only)
- Restock: add quantity to a batch's `inventory.quantity_on_hand`; `inventory_history` type RESTOCK; refresh status;
  audit_log INVENTORY_UPDATE (module INVENTORY).
- Price change: update `product.unit_price` only; past sales keep their copied `unit_price`;
  audit_log INVENTORY_UPDATE with `details = 'Price Serum: 120.00 to 125.00'`.
- Add product: validate, insert `product`, first `product_batch`, and `inventory` row; audit_log INVENTORY_UPDATE.
- Delete row (trash on an Inventory row): checked per batch. A batch can be deleted only if it has no `sale_item` and no
  `inventory_history` rows; otherwise refuse with "This batch has sales or history and cannot be deleted." In one transaction
  delete the batch's `alert_notification` rows, then its `inventory` row, then the `product_batch`. Deleting the last batch of a
  product also deletes the product. audit_log INVENTORY_UPDATE (module INVENTORY).

## Audit log
Write a row for: login (LOGIN, SALES), pay (SALE, SALES), void (VOID, SALES), restock, price change, add/edit/delete product,
Excel import (INVENTORY_UPDATE, INVENTORY). The audit log is read-only in the app.

## Login and security
- `username` + password, only `is_active = 1`. Wrong login shows one generic message. Limit to 5 tries per minute.
- Session timeout after inactivity (default 120 minutes; use 30 for production). Regenerate the session after login.
- No sign-up page. HTTPS only in production. `APP_DEBUG=false` in production.

## Alerts and notifications
- Alerts are tracked in `alert_notification` so each one is shown once and emailed once. `AlertService::sync(inventory)` runs right
  after every `stock_status` refresh:
  - status is LOW STOCK, OUT OF STOCK or NEAR EXPIRY: make sure ONE open row (`resolved_at IS NULL`) exists for that batch and type.
    If an open row of another type exists for the batch, set its `resolved_at = now()` first. Never create a duplicate open row.
  - status is OKAY: set `resolved_at = now()` on the batch's open rows.
- `/alerts` lists the open rows with product, batch, quantity, expiry. The Alerts button shows the count of open rows.
- Daily email at 08:00 (`Asia/Manila`) to active OWNER accounts through Gmail: the open rows with `emailed_at IS NULL`. After sending,
  set `emailed_at = now()` on them. If there are none, send nothing. Not by SMS.

## Password recovery (FR-03)
- Gmail option: Laravel's password broker with table `password_reset_tokens`; because the column is `password_hash`, save the new
  password with `$user->password_hash = Hash::make($password)`.
- SMS option (planned): 6-digit code stored hashed in `password_reset_tokens.token`, valid 10 minutes, max 5 tries, sent by Semaphore.
- Same response whether or not the account exists. Only active accounts. Each link or code works once.

## Excel (maatwebsite/excel)
- Export: Sales Records by date range, Inventory list, Audit log (OWNER only).
- Import (planned): template columns `product_number, product_name, category_name, supplier_name, cost_price, unit_price,
  batch_number, expiry_date, quantity, reorder_level`. Validate first, show rows with errors, save only valid rows in a transaction,
  default `image_path = 'images/placeholder.png'`, write an audit_log row.

## Money and dates
Money is DECIMAL with 2 decimals, shown with the peso sign. Timezone `Asia/Manila`. Date shown as `m/d/Y`.
