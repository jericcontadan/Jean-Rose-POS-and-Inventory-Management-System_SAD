# 05 - Build plan (one screen at a time)

Always: one branch per screen (`feature/<name>`), small commits, test in Chrome (and tablet view with Ctrl+Shift+M), write a test,
run `php artisan test`, then commit and move the board card. Stop after each task and summarize changed files.
Never run migrate. Never touch `.env`.

## Release 1 (core)
| Step | Task | Done when |
|---|---|---|
| 0 | Models for the 12 tables (see `02-database-schema.md`), `config/pos.php`, base Blade layout (Bootstrap CDN, viewport, menu by role), `/health` | Models load rows; layout shows on tablet width |
| 1 | Login and logout, active accounts only, LOGIN audit row, redirect by role, owner-only middleware, rate limit | OWNER and CASHIER can log in; cashier gets 403 on `/inventory` |
| 2 | POS screen: search, categories, product cards with badges, cart (add, change quantity, remove, cancel) | Cart total is correct; out-of-stock cannot be added |
| 3 | Payment and Receipt with the Pay Now transaction (FEFO, near-expiry discount, cash and change, receipt, print) | Stock goes down; sale, sale_items, history and audit rows exist; failure rolls back |
| 4 | Inventory screen (table, search, status, expiry) | Statuses match `04-business-rules.md` |
| 5 | Sales Records (date filter, table, daily summary) | Sales appear by date with discount and total |
| 6 | Audit Log (filters, read-only) | Login, sale and inventory rows show |

## Extras (in this order)
7 Void a Sale | 8 Alerts + daily Gmail email | 9 Update Stock and Price | 10 Add or Edit Product |
11 Forgot and Reset Password (Gmail first) | 12 Excel export | 13 Excel import | 14 Custom 403 and session expired pages.
Do not start an extra until Release 1 passes its tests and is deployed once.

## Definition of done for every task
- Works on tablet landscape and PC. Matches the wireframe.
- Validation and error messages shown. Owner-only enforced on the server.
- Feature test written and passing. Postman request saved for any route we demo.
- Audit log rows written where `04-business-rules.md` says so.
- A teammate can explain how it works (ask the AI for a 5-line explanation).
- Committed on its branch, pull request reviewed, card moved, bugs logged in `docs/06-testing.md`.

## Suggested prompt per task
"Build <task> only, following docs/03-screens-and-routes.md and docs/04-business-rules.md and the wireframe.
Do not run any database or migrate command. List the files you changed and give me the test to run."

## Schedule (weeks 13 to 18, adjust to real dates)
13 setup, models, login | 14 POS, payment | 15 inventory, sales records, audit log, FIRST DEPLOYMENT |
16 GitHub Actions, extras, bug fixing | 17 testing, test and bug logs, user manual, remaining extras | 18 rehearse, handover.
