# 03 - Screens, routes and access

Wireframes are in `docs/wireframes/` (SVG and PNG). Follow them. Each wireframe shows route, access, tables and SRS item.
The look (colors, fonts, layout) comes from the team mockup in `docs/design/`: see `docs/design/design-tokens.md` for the screen list,
the Bootstrap overrides, and the points where the mockup changes a wireframe (header tabs, Cancel / Void, row buttons, and so on).
All routes need login except: `/login`, `/forgot-password`, `/reset-password`, `/health`.
Layout: Blade layout with Bootstrap 5 CDN, viewport meta tag, large touch buttons (>= 44 px), landscape tablet first.
Menu: OWNER sees POS, Sales Records, Inventory, Audit Log, Log Out. CASHIER sees POS, Log Out. Owner-only routes return 403 for the cashier.

| # | Screen | Route(s) | Access | Tables | SRS | Release |
|---|---|---|---|---|---|---|
| 1 | Login | GET/POST `/login`, POST `/logout` | All | user_account, audit_log | FR-01, FR-02 | Core |
| 2 | Forgot and Reset Password | `/forgot-password`, `/reset-password/{token}` | All | user_account, password_reset_tokens | FR-03 | Extra |
| 3 | Point of Sale | GET `/pos` | Cashier, Owner | product, product_batch, inventory | FR-04, FR-07 | Core |
| 4 | Payment and Receipt | POST `/pos/pay`, GET `/receipts/{sale}` | Cashier, Owner | sale, sale_item, inventory, inventory_history, audit_log | FR-04, FR-05 | Core |
| 5 | Menu by account type | all pages | Owner, Cashier | user_account | FR-01 | Core |
| 6 | Alerts | GET `/alerts` | Cashier, Owner | inventory, product_batch, alert_notification | FR-07 | Extra |
| 7 | Sales Records | GET `/sales` | Owner | sale, sale_item, product | FR-08 | Core |
| 8 | Void a Sale | POST `/sales/{id}/void` | Owner | sale, inventory, inventory_history, audit_log | FR-08, FR-09 | Extra |
| 9 | Inventory | GET `/inventory` | Owner | product, product_batch, inventory, supplier | FR-06, FR-07 | Core |
| 10 | Update Stock and Price | PUT `/inventory/{id}/stock`, PUT `/products/{id}/price` | Owner | inventory, inventory_history, product, audit_log | FR-06, FR-09 | Extra |
| 11 | Excel Import and Export | GET `/exports/*`, POST `/imports/products` | Owner | product, product_batch, inventory, sale, audit_log | FR-06, FR-08 | Extra (export), Planned (import) |
| 12 | Add or Edit Product | `/products/create`, `/products/{id}/edit` | Owner | product, product_batch, inventory, category, supplier | FR-06 | Extra |
| 13 | Audit Log | GET `/audit-log` | Owner | audit_log | FR-09 | Core |
| 14 | Access Denied, Session Expired | 403 page, login redirect | All | none | Form A | Planned |
| - | Health check | GET `/health` returns `ok` | Public | none | deploy | Core |

## Screen details
**POS (3):** search box, category tabs (All + categories), product cards (name, price, image, badges LOW, OUT OF STOCK, NEAR EXPIRY),
Alerts button with count, right-side Order Details panel (items, subtotal, near-expiry discount lines, total, CANCEL, VOID, PAY NOW).
CANCEL and VOID only clear the current unpaid order. Out-of-stock products cannot be added.
**Payment (4):** payment box shows total, method Cash, cash received input, change. Cash received must be >= total.
After confirm: save sale, show receipt with receipt no `S-` + sale_id padded to 4 digits, date, cashier, items, discount, total, cash, change,
buttons PRINT RECEIPT (browser print, CSS print layout) and NEW ORDER.
**Sales Records (7):** date selector (default today), table (date, category, product, quantity, price, discount, total amount),
VOID (needs reason, owner only), EXPORT TO EXCEL, CLOSE SALES (daily summary: transactions, cancelled, total sales).
**Inventory (9):** search, table (product, price, stocks, supplier, one status, expiration date), buttons Update Stocks, Update Price,
Delete Row (only if no history), Add Product, Import Excel, Export Excel, Alerts button.
**Audit Log (13):** read-only, newest first, filters (date, user, action), export. Never allow edit or delete.
**Add or Edit Product (12):** product number (unique), name, category, supplier, cost price, unit price (> 0), image upload (store in
`storage/app/public/products`, put the path in `image_path`; default `images/placeholder.png`), first batch (batch number, expiry date,
quantity received, reorder level). Required fields marked with *.
**Forgot and Reset (2):** username or email, choose Gmail link or SMS code. Same message whether or not the account exists.
Links and codes work once and expire (link 60 minutes, SMS code 10 minutes, max 5 tries). Only active accounts can reset.

## Tablet and UI rules
Landscape 1024-1280 px first, also PC. Buttons >= 44 px high with spacing. No hover-only actions. Number inputs use
`inputmode="numeric"`. Include `<meta name="viewport" content="width=device-width, initial-scale=1">`.
Show clear success and error messages. Confirm before void and delete. Money format: peso sign and 2 decimals.
