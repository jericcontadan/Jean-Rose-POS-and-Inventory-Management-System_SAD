# Database, shared rules and setup (Step 0)

Schema file: `docs/database/jeanrose_pos_schema.sql` (12 tables). Read it before writing models. Never change the database from Laravel code except through normal inserts and updates.

## Tables and models
Every model sets `$table`, `$primaryKey`, and `public $timestamps = false`.

| Table | Model | Primary key | Notes |
|---|---|---|---|
| `user_account` | `UserAccount` (extends `Authenticatable`) | `user_id` | Login model. See "Auth model" below. |
| `category` | `Category` | `category_id` | Skincare, Haircare, Cosmetics, Supplements |
| `supplier` | `Supplier` | `supplier_id` | |
| `product` | `Product` | `product_id` | `product_number` unique. `image_path` NOT NULL. |
| `product_batch` | `ProductBatch` | `batch_id` | `expiry_date`, `quantity_in_batch`. Unique (`product_id`, `batch_number`). |
| `inventory` | `Inventory` | `inventory_id` | One row per batch (`batch_id` unique). `quantity_on_hand`, `reorder_level`, `stock_status`. `updated_at` is set by MySQL. |
| `inventory_history` | `InventoryHistory` | `transaction_id` | `quantity_change` (+ or -), `transaction_type` RESTOCK, SALE, VOID |
| `sale` | `Sale` | `sale_id` | `subtotal`, `total_amount`, `discount_percent` (always 0, see D8), `sale_status` PAID or CANCELLED, `cash_received`, `change_amount`, `void_reason`, `voided_at`, `voided_by` |
| `sale_item` | `SaleItem` | `sale_item_id` | `batch_id`, `quantity`, `unit_price` (copied at sale time), `line_amount`, `discount_percent` |
| `audit_log` | `AuditLog` | `audit_log_id` | Read-only after insert. Never update or delete rows. |
| `password_reset_tokens` | `PasswordResetToken` | `email` (string, not incrementing) | Stores only hashed tokens or codes |
| `alert_notification` | `AlertNotification` | `alert_id` | `alert_type` LOW STOCK, OUT OF STOCK, NEAR EXPIRY; `emailed_at`, `resolved_at` |

Relations: product belongsTo category and supplier; product hasMany batches; batch hasOne inventory; sale hasMany saleItems; saleItem belongsTo batch; sale belongsTo user (cashier) and voidedBy (user).

Cast DATE and DATETIME columns to Carbon, DECIMAL columns to `decimal:2`.

## Auth model
- `config/auth.php`: the `users` provider uses `App\Models\UserAccount`.
- `getAuthPassword()` returns `password_hash`. In Laravel 11 or newer also override `getAuthPasswordName()` to return `'password_hash'`.
- The table has no `remember_token`: override `getRememberTokenName()` to return `''` and do not offer "Remember me".
- Helpers: `isOwner()`, `isCashier()`.
- Login is by **username**, and only accounts with `is_active = 1` can log in.

## Shared services (put logic here, not in controllers)
- `App\Services\AuditLogger::log($action, $module, $reference = null, $details = null, $saleId = null)`. Uses the logged-in user. `details` max 255 characters (truncate).
  - Action ENUM: LOGIN, SALE, VOID, INVENTORY_UPDATE. Module ENUM: SALES, INVENTORY. LOGIN uses module SALES.
- `App\Services\StockStatusService::refresh(Inventory $inv)` sets `stock_status` by rule D5 and then calls `AlertService::sync()` for that batch (after Step 10; before then, a no-op).
- `App\Services\PricingService`: near-expiry check and line math (see "Money" below).
- `App\Support\Refs`: `saleRef($id)` gives `S-0002`, `productRef($id)` gives `P-0001`.

## Settings
Create `config/jeanrose.php` reading from `.env`:
```php
return [
    'near_expiry_days' => (int) env('NEAR_EXPIRY_DAYS', 30),
    'near_expiry_discount_percent' => (float) env('NEAR_EXPIRY_DISCOUNT_PERCENT', 10),
    'placeholder_image' => 'products/placeholder.png',
    'sms_driver' => env('SMS_DRIVER', 'log'),          // log or semaphore
    'semaphore_api_key' => env('SEMAPHORE_API_KEY'),
    'semaphore_sender' => env('SEMAPHORE_SENDER_NAME'),
    'alert_email_time' => env('ALERT_EMAIL_TIME', '07:00'),
];
```
Add these keys (with empty or default values) to `.env.example`, never real secrets.

## Money
- Use bcmath strings or integer cents. Round half up to 2 decimals at the line level.
- `gross = unit_price × quantity`
- `line_amount = round(gross × (100 − discount_percent) / 100, 2)`
- `sale.subtotal = Σ gross`, `sale.total_amount = Σ line_amount`, discount shown = subtotal − total.
- Wireframe check: Serum 120.00 × 1 at 10% = 108.00; Makeup 150.00 × 3 = 450.00; subtotal 570.00; discount −12.00; total 558.00; cash 600.00; change 42.00. Write a unit test for exactly these numbers.

## Near-expiry
A batch is near expiry when `expiry_date` is today or within `near_expiry_days` from today (Asia/Manila). A batch whose `expiry_date` is before today is expired and is not sold (D4).

## Stock status (D5)
```
if quantity_on_hand == 0                 -> OUT OF STOCK
elseif quantity_on_hand <= reorder_level -> LOW STOCK
elseif near expiry or expired            -> NEAR EXPIRY
else                                     -> OKAY
```
Recalculate after every sale, void, restock, batch edit and import, and once a day (expiry changes with time): artisan command `jeanrose:refresh-stock-status`, scheduled daily at 00:05.

## Transactions
Pay Now, Void, Restock, Add Product, Delete Row and Import each run inside `DB::transaction(...)`. Read inventory rows with `lockForUpdate()`. Write `inventory_history` and `audit_log` inside the same transaction.

## Layout
- One Blade layout `layouts/app.blade.php` that matches the mockup header (see `docs/design/05-inventory-owner.png`): white bar with the "Jean Rose" wordmark, nav tabs for the owner, Alerts button with count, "Signed in as Owner/Cashier", Log out. Page background `--jr-bg`.
- `resources/css/app.css`: CSS variables from `docs/design/design-tokens.md`, plus shared classes (`.jr-card`, `.jr-btn`, `.jr-btn-outline`, `.jr-pill`, `.jr-badge-low`, `.jr-badge-out`, `.jr-badge-near`, `.jr-badge-ok`, `.jr-input`). Views use these classes, not inline styles.
- Fonts Nunito, Poppins and Parisienne from Google Fonts. Copy `docs/design/assets/logo.png` to `public/images/logo.png`.
- Bootstrap 5 through Vite (`npm install bootstrap`), or the CDN if Node is not installed. Ask which.
- Viewport meta tag. Touch targets at least 44 px.

## Step 0 tasks
1. Check versions: `php artisan --version`, `php -v`. Use what is installed.
2. Models and relations above. Auth provider change.
3. `config/jeanrose.php`, `.env.example` keys, `APP_TIMEZONE=Asia/Manila`.
4. Services `AuditLogger`, `StockStatusService`, `PricingService`, `Refs` with unit tests (money test above).
5. **Test database.** Create artisan command `jeanrose:make-test-db`. It reads `docs/database/jeanrose_pos_schema.sql`, replaces every `jeanrose_pos` with `jeanrose_pos_test`, and runs it. It refuses to run if the target name is not `jeanrose_pos_test`. In `phpunit.xml` set `DB_CONNECTION=mysql` and `DB_DATABASE=jeanrose_pos_test` (remove the sqlite lines). Tests use `DatabaseTransactions`.
6. `database/seeders/DemoDataSeeder.php`: insert-only and safe to re-run (`firstOrCreate`). Categories Skincare, Haircare, Cosmetics, Supplements. Suppliers SY TRADING, ABC BEAUTY, VITA TRADING. Products and batches that match the wireframes: Serum 120 (10 left, expires within 30 days), Makeup 150 (15), Vitamins 90 (2, reorder 5), Lotion 80 (0). Do not create user accounts in the seeder (the team made them by hand).
7. `public/storage` link (`php artisan storage:link`) and a placeholder image at `storage/app/public/products/placeholder.png`.
8. `GET /health`: returns JSON `{"status":"ok"}` after a `select 1` on the database, else HTTP 503.
9. The layout file, `app.css` with the design tokens and shared classes, and a `/style-check` page (local only) that shows every button, badge, input and card so the team can compare it with the mockup.
