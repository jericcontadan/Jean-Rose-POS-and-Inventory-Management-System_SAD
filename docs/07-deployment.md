# 07 - Deployment and handover summary

Full step-by-step is in the team's "Jean Rose POS Deployment Guide (Laravel + MySQL on AWS)". This file is the short checklist.

## Target
One Ubuntu server on AWS (Singapore region) running Nginx + PHP 8.3 FPM, MySQL 8 (RDS, or MySQL on the same server for lower cost),
HTTPS with a free Let's Encrypt certificate, S3 for backups, GitHub Actions for tests and deploy, Gmail for email.
Laragon is only for laptops. The owner never installs anything.

## Order
1. AWS account (owner's email and billing if possible), budget alert, MFA, region ap-southeast-1.
2. Security groups: app (80, 443 open; SSH only from team IPs) and database (3306 only from the app).
3. MySQL 8.0: load `docs/sql/jeanrose_pos.sql` (all 12 tables); create `pos_user` with a strong password.
4. EC2 server: install nginx, php8.3-fpm and extensions, composer, git, certbot. Clone the repo to `/var/www/jeanrose-pos`.
5. Server `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, DB values, `SESSION_DRIVER=file`, `CACHE_STORE=file`,
   `QUEUE_CONNECTION=sync`, `SESSION_SECURE_COOKIE=true`, `APP_TIMEZONE=Asia/Manila`, Gmail SMTP with the Google App password.
6. `composer install --no-dev --optimize-autoloader`, `php artisan key:generate`, `php artisan config:cache route:cache view:cache`,
   `php artisan storage:link`, folder permissions for `storage` and `bootstrap/cache`.
7. Nginx site pointing to `public/`, then `certbot --nginx`. Add the cron for the scheduler:
   `* * * * * cd /var/www/jeanrose-pos && php artisan schedule:run >> /dev/null 2>&1`
8. `deploy.sh` for every update (git pull, composer install, cache commands, reload php-fpm, check `/health`).
   GitHub Actions: run tests on every push; deploy by SSM (or manual `deploy.sh`).
9. Daily database backup kept 7 days plus nightly dump to S3. Test one restore.
10. CloudWatch alarms with email alerts.

## Before go-live
Delete demo and test accounts and sample data. Create the real owner account with a password the owner sets. Change all passwords and keys.
Check: HTTPS lock, login for owner and cashier, cashier 403 on owner pages, Pay Now, print receipt on the shop's tablet,
reset email, port 3306 not reachable from outside, reboot test.

## Handover package
User manual (owner and cashier), admin sheet (server, domain, backup restore, who to call), repository access or transfer,
cost and who pays, training session, signed acceptance note, and the outage plan: record sales on paper and encode them later.

## Data Privacy Act (RA 10173)
Hashed passwords, restricted accounts, audit log, data stored in the Singapore region, no real personal data in test files.
