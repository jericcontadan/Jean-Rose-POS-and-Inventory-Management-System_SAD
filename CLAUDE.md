# Jean Rose POS and Inventory Management System

Web app for Jean Rose Beauty Products Trading: point of sale (POS) and inventory management. Used in a browser on a PC or tablet (landscape). Stack: Laravel (use the version already installed), Blade views, Bootstrap, MySQL 8, Laravel Excel (maatwebsite/excel). This is a student capstone project (TUP BSIS), so the code must be simple and readable enough for students to explain.

Sources of truth:
- **Look and layout: our team's mockup** in `docs/design/`. Each screen has a PNG and a static HTML copy in `docs/design/screens/` with exact inline styles. Colors, fonts and sizes are in `docs/design/design-tokens.md`. Match the mockup as closely as you can.
- **Behavior and rules: the SDS, the wireframes in `docs/wireframes/` and the files in `docs/ai/`.** Fields, validation, database writes and access rules come from these. Where the mockup changed a button or layout on purpose, the `docs/ai/` files already say so ("Mockup:" notes).
- When a prompt says "look at screen N in the wireframes", also open the matching mockup files (table at the end of `design-tokens.md`) and build the look from the mockup.
- If the mockup and the brief disagree on a field, button or rule and no "Mockup:" note covers it, stop and ask.

## Read before coding
1. `docs/ai/00-build-plan.md` (build order, workflow, open decisions)
2. `docs/ai/01-database-and-rules.md` (tables, business rules, formats)
3. The screen file for the screen you were asked to build (`docs/ai/02` to `08`)

## Hard rules
1. **Use the existing MySQL database `jeanrose_pos` (12 tables).** Do NOT create migrations. Never run `php artisan migrate`, `migrate:fresh`, `db:wipe`, and never use `RefreshDatabase` in tests. Do not rename tables or columns. If you think the schema needs a change, stop and tell me. The team changes `docs/database/*.sql` by hand.
2. Eloquent models set `$table`, `$primaryKey` and `public $timestamps = false` (the tables have their own `created_at` / `updated_at` columns).
3. Two fixed roles: `OWNER` and `CASHIER` in `user_account.role`. No role-management screen and no permissions package. Enforce owner-only pages **on the server** with middleware, not only by hiding menu items.
4. Every action that changes stock or sales runs in **one `DB::transaction`**, locks the inventory rows (`lockForUpdate`), and writes an `audit_log` row. If any step fails, everything rolls back.
5. Never trust totals or prices from the browser. Recalculate on the server.
6. Money is `DECIMAL(12,2)`. Never use floats for money (use bcmath or integer cents inside calculations). Show pesos as `₱ 1,234.00`.
7. Passwords are stored only as a hash in `user_account.password_hash` (`Hash::make`). Never log, print or return a password, token or reset code.
8. Never commit `.env`, API keys or passwords. Do not add secrets to code.
9. Validate every input with Form Requests. Escape output. Keep CSRF protection on all forms.
10. **Out of scope** (do not build): customer mobile app, online shopping or online payments (payment is cash only), delivery platforms, barcode hardware, cash register interface, role-based access control.
11. Build **only the screen I name, then stop.** Do not start the next screen.
12. Work on a branch named `feature/<screen>`. Make small commits. Do not push and do not merge to `main` unless I ask.
13. Do not add packages without telling me why. Allowed: `maatwebsite/excel`, Bootstrap. Ask before adding anything else.
14. If a business rule is unclear, check "Open decisions" in `docs/ai/00-build-plan.md`. If it is not listed there, ask me. Do not guess.

## UI rules
- Follow the mockup in `docs/design/` for the look. Buttons and cards must be large enough for touch (about 44 px or more). No feature may depend on mouse hover.
- Put the colors and fonts in one place: CSS variables in `resources/css/app.css` from `docs/design/design-tokens.md` (`--jr-primary`, `--jr-bg`, and so on). Do not repeat hex codes across views. Bootstrap is for the grid and components; override its colors with these variables.
- Responsive layout built for tablet landscape and PC. Include the viewport meta tag. Below about 900 px wide, the header nav tabs collapse into a menu button.
- Brand: wordmark "Jean Rose" + "BEAUTY PRODUCTS TRADING" in the header; the full logo `docs/design/assets/logo.png` (copy to `public/images/`) on the login page.
- Dates shown as `M/D/YYYY` (example `11/6/2026`), times as 24 hour `HH:mm`.

## Local environment (Windows)
- Laragon, PHP at `C:\php-8.5.3`, MySQL at `127.0.0.1:3306`, database `jeanrose_pos`, user `root`.
- `.env` must have: `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `APP_TIMEZONE=Asia/Manila`.
- Dev email: Mailpit (`MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`, inbox at http://localhost:8025). Dev SMS: log the code to `storage/logs/laravel.log`.
- Commands: `php artisan serve`, `php artisan test`, `php artisan route:list`, `php artisan tinker`.

## Definition of done for a screen
- Route, controller, Form Request, Blade view that matches the wireframe.
- Owner-only rule enforced on the server (if the screen is owner-only).
- Audit log row written where the screen file says so.
- Feature tests written and passing (`php artisan test`). Tests use `DatabaseTransactions`, never `RefreshDatabase`.
- A short manual test checklist for me to try in the browser.
- A 5 line summary of what you built, which files changed, and anything you were unsure about.
