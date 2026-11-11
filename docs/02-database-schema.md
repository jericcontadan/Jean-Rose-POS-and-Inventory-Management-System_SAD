# 02 - Database schema (source of truth: `docs/sql/jeanrose_pos.sql`, 12 tables)

MySQL 8, InnoDB, utf8mb4, collation `utf8mb4_0900_ai_ci`. Do NOT create migrations. Do NOT change columns here.
If a change is needed, stop and ask the team; it is done in MySQL Workbench and then `jeanrose_pos.sql` is updated.
App login for Laravel: MySQL user `pos_user` (SELECT, INSERT, UPDATE, DELETE only on `jeanrose_pos`).

## Tables and columns
| Table | Columns |
|---|---|
| user_account | user_id PK, email (50, unique), username (120, unique), phone_number (30, null), password_hash (255), role ENUM('OWNER','CASHIER'), created_at, is_active (1 or 0) |
| category | category_id PK, category_name (80, unique) |
| supplier | supplier_id PK, supplier_name (120), contact_number (30, null), email (120, null), address (255, null) |
| product | product_id PK, category_id FK, supplier_id FK, product_name (150), product_number (60, unique), cost_price DECIMAL(12,2), unit_price DECIMAL(12,2), image_path (255, NOT NULL) |
| product_batch | batch_id PK, product_id FK, batch_number (50), expiry_date, quantity_in_batch, date_received, created_at. UNIQUE(product_id, batch_number) |
| inventory | inventory_id PK, batch_id FK (UNIQUE: one stock row per batch), quantity_on_hand, reorder_level, stock_status ENUM('LOW STOCK','OUT OF STOCK','OKAY','NEAR EXPIRY') default 'OKAY', updated_at |
| inventory_history | transaction_id PK, inventory_id FK, batch_id FK, user_id FK, quantity_change (+ or -), transaction_type ENUM('RESTOCK','SALE','VOID'), transaction_datetime |
| sale | sale_id PK, user_id FK, sale_datetime, subtotal, total_amount, discount_percent DECIMAL(5,2) default 0, sale_status ENUM('PAID','CANCELLED') default 'PAID', cash_received DECIMAL(12,2) null, change_amount DECIMAL(12,2) null, void_reason (255, null), voided_at DATETIME null, voided_by FK to user_account (null) |
| sale_item | sale_item_id PK, sale_id FK (ON DELETE CASCADE), batch_id FK, quantity, unit_price, line_amount, discount_percent DECIMAL(5,2) |
| audit_log | audit_log_id PK, user_id FK, sale_id FK (null, ON DELETE SET NULL), action_type ENUM('LOGIN','SALE','VOID','INVENTORY_UPDATE'), module ENUM('SALES','INVENTORY'), transaction_reference (50, null), details (255, null), created_at |
| password_reset_tokens | email PK (50), token (255, hashed), created_at (null). 11th table, added by Form B for FR-03. Already inside `jeanrose_pos.sql` |
| alert_notification | alert_id PK, batch_id FK to product_batch, alert_type ENUM('LOW STOCK','OUT OF STOCK','NEAR EXPIRY'), created_at, emailed_at (null), resolved_at (null). 12th table, added by Form B for FR-07 |

## Relationships (foreign keys are RESTRICT on delete, CASCADE on update unless noted)
category 1-N product | supplier 1-N product | product 1-N product_batch | product_batch 1-1 inventory |
product_batch 1-N sale_item | sale 1-N sale_item (cascade) | user_account 1-N sale, inventory_history, audit_log |
sale 1-N audit_log (set null) | inventory 1-N inventory_history | product_batch 1-N alert_notification |
user_account 1-N sale as `voided_by` (the owner who voided it).
Stock is tracked per BATCH. A product's stock is the sum of its batches' `inventory.quantity_on_hand`.

## Things the schema does NOT have (handle in code, do not add columns)
- (The schema now HAS `sale.cash_received`, `sale.change_amount`, `sale.void_reason`, `sale.voided_at`, `sale.voided_by` and the
  `alert_notification` table. Use them: see `04-business-rules.md`.)
- No `is_active` on product: a product with sales or history cannot be deleted (FK restrict). Show a friendly message.
- No Close Sales storage: "Close Sales" shows and exports a daily summary only; it does not lock data.
- `audit_log.module` allows only 'SALES' or 'INVENTORY': use 'SALES' for LOGIN, SALE, VOID rows and 'INVENTORY' for inventory/price/import rows.

## Eloquent model setup (every model)
```php
protected $table = 'sale';          // exact table name
protected $primaryKey = 'sale_id';  // exact primary key
public $timestamps = false;         // tables use their own datetime columns
protected $guarded = [];            // or list $fillable
```
Models: UserAccount, Category, Supplier, Product, ProductBatch, Inventory, InventoryHistory, Sale, SaleItem, AuditLog, AlertNotification.
(`password_reset_tokens` is used through Laravel's password broker, so it needs no model.)
`Sale` has two user relations: `cashier()` on `user_id` and `voidedBy()` on `voided_by`.
`UserAccount extends Authenticatable`: `getAuthPassword()` returns `$this->password_hash`;
`getRememberTokenName()` returns `''`. In `config/auth.php` set `providers.users.model` to `App\Models\UserAccount::class`.
Login uses `username` plus `is_active = 1`.

## .env must contain
`SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `APP_TIMEZONE=Asia/Manila`
(Laravel defaults would look for sessions, cache and jobs tables that do not exist).
