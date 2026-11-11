# Steps 9, 12, 13 and 14: Audit Log, Excel export, Excel import, CI

Wireframes: screens 11 and 13. SRS FR-06, FR-08, FR-09. Access: OWNER only.

Mockup: `docs/design/09-audit-log-export.png` (audit log, with the export card on the right) and the import card in `06-stock-price-product-import.png`.

## Step 9: Audit Log (`GET /audit-log`)
- Filters: DATE (single day, default today; optional FROM and TO), USER (All users ▾ or one username), ACTION (All actions ▾, LOGIN, SALE, VOID, INVENTORY_UPDATE). Buttons APPLY and EXPORT EXCEL (Step 12).
- Table, newest first: Date / Time | User | Action | Module | Reference | Details.
  Example: 11/6/2026 09:15 | owner | INVENTORY_UPDATE | INVENTORY | P-0001 | Price 120 to 125. Show "-" for an empty reference.
- Footer text "Read-only list. Newest records first." Paginate 50 per page.
- **Read-only:** no edit or delete routes, buttons or model methods. Add a test that no route updates or deletes `audit_log`.

### Step 9 tests
- Filters by date, user and action work together.
- Order is newest first. Cashier gets 403.

## Step 12: Excel export (`maatwebsite/excel`)
Install with `composer require maatwebsite/excel` (tell me first).
- `GET /exports/inventory` → `inventory_YYYY-MM-DD.xlsx`: Product number, Product, Category, Supplier, Batch, Expiry date, Stock, Reorder level, Status, Cost price, Unit price.
- `GET /exports/sales?from=&to=` → `sales_FROM_TO.xlsx`: Receipt no., Date, Time, Cashier, Status, Category, Product, Batch, Quantity, Unit price, Discount %, Line amount. Second sheet: daily totals (paid sales only).
- `GET /exports/audit-log?from=&to=` → `audit_log_FROM_TO.xlsx`: same columns as the screen.
- Export card (mockup: right side of the Audit Log page, also reachable from the Export buttons): "What to export" choice Inventory list / Sales Records / Audit log, From and To dates ("Dates apply to Sales Records and Audit log."), "Download .xlsx".
- Wire up the EXPORT buttons on Inventory, Sales Records and Audit Log.
- Money columns as numbers with 2 decimals, not text. Dates as real Excel dates.
- Tests: each export returns a download with the right file name and header row (use `Excel::fake()`).

## Step 13: Excel import (`/imports/products`)
1. **Download the template** (`GET /imports/products/template` → `products_template.xlsx`). Columns: product_number, product_name, category, supplier, cost_price, unit_price, batch_number, expiry_date, quantity, reorder_level. One example row.
2. **Choose your file** (BROWSE, .xlsx only, max 2 MB) and **CHECK FILE**: read every row, save nothing, and list problems: Row | Problem | Action ("Fix in file"). Checks: required fields; prices are numbers ≥ 0; quantity and reorder level whole numbers ≥ 0; expiry is a valid date; category and supplier exist (match names, ignore case); product_number not duplicated inside the file; batch_number not already used for that product.
   - A product_number that already exists in the database adds a **new batch** to that product (restock). A new product_number creates the product (placeholder image).
3. Show "28 rows are ready. 2 rows have errors." and "Only valid rows are saved, and the import is written to the audit log." Buttons IMPORT N ROWS and CANCEL.
4. **Import:** keep the checked file in `storage/app/imports/` (not public) between check and import, and check it again before saving. One transaction for all valid rows: product (if new), batch, inventory, RESTOCK history, status refresh. One audit row: `INVENTORY_UPDATE` / INVENTORY / reference "IMPORT" / "Imported 28 rows from products_nov2026.xlsx". Delete the stored file afterwards.
- Tests: a file with 2 bad rows reports exactly those rows; importing saves only the good rows and one audit row; a duplicate product number inside the file is reported.

## Step 14: CI (GitHub Actions)
File `.github/workflows/tests.yml`, runs on push and pull request:
- `services: mysql:8` with an empty root password or a password from `secrets`.
- Steps: checkout, setup PHP (`shivammathur/setup-php`, same major version as the team), `composer install`, copy `.env.example` to `.env`, `php artisan key:generate`, load `docs/database/jeanrose_pos_schema.sql` into MySQL, run `php artisan jeanrose:make-test-db`, run `php artisan test`.
- Never put real secrets in the workflow file. A failing test or a broken schema fails the pipeline (SDS DevOps pipeline).
- Deployment to AWS is a separate task. Do not add it unless I ask.
