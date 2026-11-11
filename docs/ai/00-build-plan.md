# Build plan

Build in this order. Each step is one branch, one pull request, and one prompt from `docs/ai/09-prompts.md`. Finish, test and merge a step before starting the next one.

| Step | Branch | What to build | Brief file | Release (SDS) |
|---|---|---|---|---|
| 0 | `feature/setup` | Models, auth config, layout, config file, test database, demo data, `/health` | `01-database-and-rules.md` | Core |
| 1 | `feature/login` | Login, logout, side menu by role, owner-only middleware, Access Denied (403), Session Expired | `02-login-menu-errors.md` | Core |
| 2 | `feature/pos` | POS screen: search, categories, product cards, badges, order panel, near-expiry discount | `04-pos-payment-receipt.md` | Core |
| 3 | `feature/payment` | Pay Now transaction, payment box, receipt page, print | `04-pos-payment-receipt.md` | Core |
| 4 | `feature/inventory` | Inventory list with search and status | `07-inventory-products.md` | Core |
| 5 | `feature/products` | Add or Edit Product, add a batch, delete a row | `07-inventory-products.md` | Extra |
| 6 | `feature/stock-price` | Update Stocks and Update Price | `07-inventory-products.md` | Extra |
| 7 | `feature/sales-records` | Sales Records list by date | `06-sales-records-void.md` | Core |
| 8 | `feature/void-sale` | Void a Sale | `06-sales-records-void.md` | Extra |
| 9 | `feature/audit-log` | Audit Log screen with filters | `08-excel-audit-log.md` | Core |
| 10 | `feature/alerts` | Alerts list, Alerts button count, daily Gmail email | `05-alerts.md` | Extra |
| 11 | `feature/password-reset` | Forgot and Reset Password (Gmail link first, then SMS code) | `03-password-recovery.md` | Extra |
| 12 | `feature/excel-export` | Export inventory, sales and audit log to .xlsx | `08-excel-audit-log.md` | Extra |
| 13 | `feature/excel-import` | Import products and stock from the template | `08-excel-audit-log.md` | Planned |
| 14 | `feature/ci` | GitHub Actions: load schema into a throwaway MySQL, run tests | `08-excel-audit-log.md` (CI section) | Planned |

Core steps (0 to 4, 7, 9) make a working Release 1. Do those first. Extras come after, if time allows.

## Workflow for every step
1. `git checkout main` and `git pull`.
2. `git checkout -b <branch>`.
3. Paste the prompt for the step. Claude Code reads `CLAUDE.md` and the brief file, then shows a plan. Approve or correct the plan before it writes code.
4. Claude Code builds the screen, writes tests, runs `php artisan test`.
5. You test it in the browser with the manual checklist it gives you.
6. Commit, push the branch, open a pull request, a teammate reviews, merge.

## Open decisions
These are defaults chosen so the build can move. Each one is marked "confirm" in the SDS or was not decided. Use the default unless the team changes it here.

| # | Question | Default used in this brief |
|---|---|---|
| D1 | Near-expiry window | 30 days (`NEAR_EXPIRY_DAYS=30`) |
| D2 | Near-expiry discount | 10 percent (`NEAR_EXPIRY_DISCOUNT_PERCENT=10`) |
| D3 | Which batch is sold first | Earliest expiry first (FEFO). One order line can split into several `sale_item` rows if it spans batches. |
| D4 | Expired batches (expiry date already passed) | Not sold. Hidden from POS stock, shown as NEAR EXPIRY in Inventory with the red "Expired" text. |
| D5 | Stock status when two apply | First match wins: OUT OF STOCK (qty 0), LOW STOCK (qty ≤ reorder level), NEAR EXPIRY (within window or expired), OKAY. This follows the SDS order. The discount uses `expiry_date` directly, not `stock_status`. |
| D6 | Badge on a POS product card | Product total = sum of `quantity_on_hand` of its unexpired batches. OUT OF STOCK if 0. LOW if total ≤ the reorder level of its newest batch. NEAR EXPIRY if the batch that would be sold next is near expiry. |
| D7 | POS Cancel and Void buttons | Mockup: one "Cancel / Void" button clears the whole order after a confirm. Each order line has − / + quantity buttons and Remove. None of these write to the database. |
| D8 | Sale-level discount `sale.discount_percent` | Always 0. Discounts are per item in `sale_item.discount_percent`. |
| D9 | Receipt and reference numbers | Sale: `S-` + sale_id padded to 4 digits (`S-0002`). Product: `P-` + product_id padded to 4 (`P-0001`). Computed, not stored. |
| D10 | Close buttons | Sales Records "Close" returns to POS. No data change. Mockup: Inventory has no Close button (use the POS tab). |
| D11 | Inventory table rows | One row per batch, because stock and expiry live on the batch. Mockup shows one row per product; show the batch number in small muted text under the product name. |
| D21 | Sales Records Void button | Mockup: a Void button on each row. Rows are items, but a void cancels the **whole sale** that row belongs to. Group rows by sale (receipt no. and time) so this is clear, and show Void once per sale. |
| D22 | Stock, price, add product and import forms | Mockup shows them as cards over Inventory. Build each as its own page with the card centered (simpler validation). Use Bootstrap modals only if the team asks. |
| D23 | NEAR EXPIRY filter tab on POS | Wireframe has it; mockup does not. Leave it out; the badge on each card is enough. |
| D12 | Delete Row | Allowed only for a batch with no sales and no history except its first RESTOCK. Otherwise show "This batch has sales and cannot be deleted." A product is deleted only when its last batch is deleted. |
| D13 | Product image | Optional. When none is uploaded, `image_path` stores `products/placeholder.png` (the column is NOT NULL). |
| D14 | Idle logout (session expired) | 30 minutes (`SESSION_LIFETIME=30`). |
| D15 | Password rules | At least 8 characters with at least one letter and one number. |
| D16 | Reset link and code lifetime | Gmail link: 60 minutes. SMS code: 10 minutes. Both work once. |
| D17 | Daily alert email time | 7:00 AM Asia/Manila, to every active OWNER account. Sent only when there are new alerts. |
| D18 | "Each alert is shown once" | One `alert_notification` row per batch and alert type while the problem lasts. It is emailed once (`emailed_at`). It closes (`resolved_at`) when the status is back to OKAY. |
| D19 | Restock and `quantity_in_batch` | Restock adds the quantity to both `inventory.quantity_on_hand` and `product_batch.quantity_in_batch`. |
| D20 | Failed logins | Not written to `audit_log` (the ENUM has only LOGIN). Throttled to 5 attempts per minute. |
