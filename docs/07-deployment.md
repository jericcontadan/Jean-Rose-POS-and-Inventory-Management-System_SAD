# 07 - Deployment checklist (AWS Ubuntu + Nginx + PHP 8.3 + MySQL 8)

The full step-by-step guide is the team's "Jean Rose POS Deployment Guide (Laravel + MySQL on AWS)". This file is the short checklist:
tick each box on the server. Laragon is only for laptops. The owner never installs anything.

Target: one Ubuntu server on AWS (Singapore, ap-southeast-1), Nginx + PHP 8.3 FPM, MySQL 8 (RDS or on the same server),
HTTPS with Let's Encrypt, Brevo Transactional Email API for email (Gmail SMTP as backup). App folder: `/var/www/jeanrose-pos`.

## 1. AWS account and network
- [ ] AWS account (owner's email and billing if possible), budget alert, MFA, region ap-southeast-1.
- [ ] Security groups: app server (80 and 443 open, SSH only from team IPs); database (3306 only from the app server).

## 2. Server packages and PHP extensions
- [ ] `sudo apt install nginx git unzip certbot python3-certbot-nginx`
- [ ] `sudo apt install php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd`
- [ ] Composer 2 installed.
- [ ] `php -m` lists: **gd, zip, mbstring, xml, curl, pdo_mysql, fileinfo** (fileinfo is built into php8.3-common).
      gd, zip and xml are needed by the Excel export; fileinfo checks uploaded product images.
- [ ] PHP upload limits, so a 2 MB product image fits: in `/etc/php/8.3/fpm/php.ini` set `upload_max_filesize = 4M`, `post_max_size = 8M`,
      then `sudo systemctl reload php8.3-fpm`.

## 3. Database (MySQL 8)
Run these in order, as the MySQL admin user:
1. [ ] `docs/sql/jeanrose_pos.sql`: **ONCE ONLY**, on the new empty server. It creates the `jeanrose_pos` schema and all 12 tables.
       WARNING: it DROPs every table first. Never run it again after go-live or all sales are lost.
2. [ ] Create the app's database user (strong password, kept only in the server `.env`):
       ```sql
       CREATE USER 'pos_user'@'localhost' IDENTIFIED BY '<strong password>';
       GRANT SELECT, INSERT, UPDATE, DELETE ON jeanrose_pos.* TO 'pos_user'@'localhost';
       ```
       On RDS use the app server's private IP or `'%'` with the security group locked down, instead of `'localhost'`.
3. [ ] Check: `SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='jeanrose_pos';` returns **12**.
4. [ ] The real owner account is created in step 6 (tinker + `Hash::make`). No SQL file holds a password.

**NEVER run on the server:**
| File | Why |
|---|---|
| `reset_sample_data.sql` | Deletes all products, batches, stock, sales and alerts. Local testing only. |
| `sample_data.sql` | Demo products. Local testing only. |
| `jeanrose_pos_test.sql` | Builds the empty test database for `php artisan test`. |
| `jeanrose_pos_upgrade.sql` | Not needed: only for an old 10-table database. A fresh server already has 12 tables. |
| `password_reset_tokens.sql` | Obsolete. The table is already in `jeanrose_pos.sql`. |

Never run `php artisan migrate`, `migrate:fresh` or `db:wipe` (and never `composer setup`; that script was removed). This project has no migrations.

## 4. Get the code
- [ ] `sudo git clone <repo> /var/www/jeanrose-pos` and `cd /var/www/jeanrose-pos`
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `cp .env.example .env`, then fill in the server values (section 5). Never commit `.env`.

## 5. Production .env (key names only, no values here)
| Key | Production value or note |
|---|---|
| `APP_NAME` | Jean Rose POS |
| `APP_ENV` | `production` |
| `APP_KEY` | made by `php artisan key:generate` (first setup only) |
| `APP_DEBUG` | `false` (true would show passwords and code on error pages) |
| `APP_URL` | `https://<your domain>`, with no slash at the end. Every link, including the reset password email, uses it. |
| `APP_TIMEZONE` | `Asia/Manila` |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | `stack`, `daily`, `warning` |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `mysql`, server or RDS host, `3306`, `jeanrose_pos`, `pos_user`, the strong password |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_SECURE_COOKIE` | `file`, `120`, `true` (HTTPS only) |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK` | `file`, `sync`, `local` |
| `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Gmail SMTP backup: `smtp`, Gmail host and port, the Gmail address, the Google App password |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | the Brevo-verified sender address, `"${APP_NAME}"` |
| `BREVO_API_KEY` | from Brevo > SMTP & API > API keys (empty means SMTP only) |
| `POS_MAIL_VIA` | `brevo` |
| `NEAR_EXPIRY_DAYS`, `NEAR_EXPIRY_DISCOUNT_PERCENT` | the values the owner confirmed |

## 6. Laravel setup commands
- [ ] `php artisan key:generate`: **first setup only**. Running it again logs everyone out and breaks encrypted data.
- [ ] `php artisan storage:link`: once per server. Links `public/storage` to `storage/app/public`, so uploaded product images show.
- [ ] Permissions (the web server user is `www-data`):
      ```
      sudo chown -R www-data:www-data storage bootstrap/cache
      sudo chmod -R 775 storage bootstrap/cache
      ```
- [ ] `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`
      (run these after every `.env` change too; a cached config ignores `.env` edits until you run config:cache again).
- [ ] Create the real owner account (the owner types the password):
      `php artisan tinker`, then
      `App\Models\UserAccount::create(['email' => '...', 'username' => '...', 'password_hash' => Hash::make('...'), 'role' => 'OWNER', 'is_active' => 1]);`

## 7. Nginx and HTTPS
- [ ] Site `root /var/www/jeanrose-pos/public;` with `index index.php;`, `try_files $uri $uri/ /index.php?$query_string;`, PHP via `php8.3-fpm.sock`.
- [ ] `server_name <your domain>;` set explicitly. Add a `default_server` block that returns `444`, so requests with an unknown Host never reach Laravel.
- [ ] `client_max_body_size 5m;` (the Nginx default of 1 MB would reject 2 MB product images).
- [ ] `sudo nginx -t && sudo systemctl reload nginx`, then `sudo certbot --nginx -d <your domain>`.

## 8. Scheduler (daily jobs)
`routes/console.php` runs `pos:refresh-stock-status` at 06:00 and `pos:send-daily-alerts` at 08:00 (Asia/Manila).
Without cron, near-expiry status never updates and the owner never gets the alert email.
- [ ] Add the cron line as **www-data**, so log and cache files are not owned by root: `sudo crontab -u www-data -e`, then add
      `* * * * * cd /var/www/jeanrose-pos && php artisan schedule:run >> /dev/null 2>&1`
- [ ] Check: `sudo -u www-data php artisan schedule:list` shows both jobs and their next run time.

## 9. Every update (after go-live)
```
cd /var/www/jeanrose-pos
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo systemctl reload php8.3-fpm
php artisan up
curl -s https://<your domain>/health      # must print: ok
```
Do NOT run key:generate, storage:link or any SQL file during an update.

## 10. Backups and monitoring
- [ ] Daily database backup kept 7 days, plus a nightly `mysqldump` to S3. Also back up `storage/app/public` (product images). Test one restore.
- [ ] CloudWatch alarms with email alerts. GitHub Actions runs the tests on every push.

## Before go-live
- [ ] Delete demo and test accounts and sample data. Create the real owner account with a password the owner sets. Change all passwords and keys.
- [ ] HTTPS lock shows. `APP_DEBUG=false` (an unknown URL shows a plain 404 page, not code).
- [ ] Login works for owner and cashier. The cashier gets 403 on `/inventory`, `/sales`, `/audit-log`.
- [ ] Pay Now works and the receipt prints on the shop's tablet. Void works.
- [ ] The reset password email arrives and its link opens the **real domain** (`APP_URL`).
- [ ] An uploaded product image shows on the POS screen (storage:link and permissions are OK).
- [ ] Excel export downloads. Port 3306 is not reachable from outside. The site comes back after a server reboot.

## Handover package
User manual (owner and cashier), admin sheet (server, domain, backup restore, who to call), repository access or transfer,
cost and who pays, training session, signed acceptance note, and the outage plan: record sales on paper and encode them later.

## Data Privacy Act (RA 10173)
Hashed passwords, restricted accounts, audit log, data stored in the Singapore region, no real personal data in test files.
