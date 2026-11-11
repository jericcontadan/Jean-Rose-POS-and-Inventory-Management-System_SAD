# CLAUDE.md - Jean Rose POS and Inventory Management System

You are helping a team of 5 BSIS students build a capstone web app for a real shop
(Jean Rose Beauty Products Trading). The code must be simple enough for students to
read, explain and defend. Read the files in `docs/` before building anything.

## Stack (do not change without asking)
- Laravel 13, PHP 8.3, MySQL 8 (the schema already exists: `docs/sql/jeanrose_pos.sql`, 12 tables).
- Pages: Blade templates + Bootstrap 5 from a CDN + small plain JavaScript (or Alpine.js from a CDN).
- NO Vite, NO npm, NO React/Vue, NO Docker. Do not use `@vite` in layouts.
- Excel: `maatwebsite/excel`. Email: Gmail SMTP (Mailpit on localhost). Tests: Laravel tests + Postman.
- Tablet-first: design for landscape 1024-1280 px, also works on PC. Touch targets >= 44 px,
  no hover-only features, correct input types (`inputmode="numeric"` for quantity and cash).

## Hard rules (never break these)
1. The database schema comes from `docs/sql/jeanrose_pos.sql` (12 tables). NEVER create migrations, NEVER run
   `php artisan migrate`, `migrate:fresh`, `db:wipe`, or any DROP/TRUNCATE. Do not rename tables or columns.
   If the schema must change, STOP and tell me what change you need; I will do it in MySQL Workbench.
2. NEVER read, print, edit or commit `.env`, passwords, API keys, or the Gmail App password.
   Use `.env.example` only for key names.
3. Only OWNER and CASHIER exist. The cashier sees ONLY the POS (POS, payment, receipt, alerts, log out).
   Sales Records, Inventory, Audit Log, Excel and user-sensitive pages are OWNER only, enforced on the
   SERVER (middleware or Gate), not just hidden in the menu. No role-management screen (Form A: RBAC is out of scope).
4. Pay Now and Void MUST run inside one `DB::transaction()` (all steps or nothing).
5. Passwords only with `Hash::make()` into `user_account.password_hash`. Never store plain passwords.
6. Use Eloquent or the query builder with bindings. Never build SQL by joining strings.
7. Every form has `@csrf`. Validate all input on the server.
8. Keep out-of-scope items OUT (Form A, see `docs/01-project-overview.md`): customer mobile app, online
   shopping/payment, delivery platforms, barcode hardware, cash register interface, hardware, and
   role-based access control screens.

## How to work
- Build ONE screen or feature at a time, the one I name. Then stop and summarize the files you changed.
- Follow `docs/03-screens-and-routes.md` and the wireframes in `docs/wireframes/` for fields, buttons and rules.
- **Look and layout come from our mockup in `docs/design/`.** Each screen has a PNG and a static HTML copy in `docs/design/screens/` with
  exact colors, sizes and spacing. Colors, fonts and the Bootstrap overrides are in `docs/design/design-tokens.md`, which also lists
  where the mockup differs from the wireframes (follow the mockup there). If the mockup and the docs disagree on a rule, stop and ask.
- Follow `docs/08-architecture.md` for the folder layout and layers: thin controllers, business rules in services
  (`SaleService`, `InventoryService`, `StockStatusService`, `BatchPickerService`, `AuditService`, `AlertService`), only services change stock
  and write `audit_log`.
- Follow business rules in `docs/04-business-rules.md` exactly (stock, discount, void, audit log).
- Write a feature test for each feature (see `docs/06-testing.md`) and tell me how to run it.
- Keep code short and commented in plain English. After each feature, explain in 5 lines how it works.
- Ask before adding any package. Ask before running any command that changes data.
- Commit messages: `Add <thing> (closes #<issue>)`. One branch per screen: `feature/<name>`.

## Models
Every model sets `$table`, `$primaryKey` and `public $timestamps = false;` (see `docs/02-database-schema.md`).
`UserAccount` extends `Authenticatable`, `getAuthPassword()` returns `password_hash`, remember token disabled.

## If this file already has other guidelines (for example Laravel tools)
Keep them below this section. When they conflict with the rules above, the rules above win.
