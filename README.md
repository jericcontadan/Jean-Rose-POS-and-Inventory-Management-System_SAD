# Jean Rose POS and Inventory Management System

Capstone project (BSIS-3C, Team 4). A web app for a beauty products shop: point of sale, inventory
with batches and expiry, sales records, audit log, Excel import/export, and email alerts.
Built for tablet and PC browsers.

## Stack
Laravel 13 (PHP 8.3), MySQL 8, Blade + Bootstrap 5 (CDN), Laravel Excel, Gmail SMTP.

## Setup on your laptop (Windows, Laragon)
1. Install Laragon (Full), Git, VS Code, MySQL Workbench, Postman. In Laragon click **Start All**.
2. Clone the repo into `C:\laragon\www`:
   ```
   git clone https://github.com/<owner>/<repo>.git jeanrose-pos
   cd jeanrose-pos
   composer install
   copy .env.example .env
   php artisan key:generate
   ```
3. In MySQL Workbench (as root) run `docs/sql/jeanrose_pos.sql` (all 12 tables; it erases existing data), then
   `docs/sql/sample_data.sql`. If you already have a database with data, run `docs/sql/jeanrose_pos_upgrade.sql` instead. Create the app user:
   ```sql
   CREATE USER 'pos_user'@'localhost' IDENTIFIED BY 'YourPassword';
   GRANT SELECT, INSERT, UPDATE, DELETE ON jeanrose_pos.* TO 'pos_user'@'localhost';
   FLUSH PRIVILEGES;
   ```
4. Edit `.env` (never commit it): `DB_DATABASE=jeanrose_pos`, `DB_USERNAME=pos_user`,
   `DB_PASSWORD=YourPassword`, `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`.
   For local email use Mailpit: `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025` (inbox at http://localhost:8025).
5. Run `php artisan config:clear` then `php artisan serve` and open http://localhost:8000.

## Rules
- Do NOT run `php artisan migrate`. The schema is `docs/sql/jeanrose_pos.sql` (12 tables).
- Never commit `.env`. Never paste secrets into chat or issues.
- One branch per screen, pull request with one review, commit messages like `Add login (closes #1)`.

## Docs
See the `docs/` folder: overview, schema, screens and routes, business rules, build plan,
testing, deployment, architecture (cloud diagram in `docs/architecture/`).
