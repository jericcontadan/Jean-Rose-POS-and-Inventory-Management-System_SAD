# Step 10: Alerts and Notifications

Wireframe: screen 6. SRS FR-07. Tables `inventory`, `product_batch`, `alert_notification`. Access: CASHIER and OWNER (list); OWNER gets the daily email.

## AlertService::sync(Inventory $inv)
Called by `StockStatusService::refresh` after every change and by the daily refresh command.
- If `stock_status` is LOW STOCK, OUT OF STOCK or NEAR EXPIRY: make sure one **open** `alert_notification` row exists for (`batch_id`, that type). If an open row exists for a different type of the same batch, set its `resolved_at = now()` first. Never create a duplicate open row (D18).
- If `stock_status` is OKAY: set `resolved_at = now()` on every open row of that batch.

Mockup: `docs/design/08-alerts-daily-email.png`. The left card ("Alerts (3)" list and "Who gets what") is the `/alerts` page. The right side only explains badges and the email; build the email from it, not as a page.

## Alerts list (`GET /alerts`)
- Title "ALERTS (N)", N = number of open rows.
- Groups: LOW STOCK ("Vitamins - 2 left (reorder level 5)"), OUT OF STOCK ("Lotion - 0 left"), NEAR EXPIRY ("Serum - expires 11/25/2026, discounted on sale").
- Each row has VIEW: owner goes to that batch in Inventory; cashier goes to POS filtered to that product (cashier cannot open Inventory).
- The ALERTS button in the top bar of every page shows N. Use a view composer, cached for 60 seconds at most.
- A "WHO GETS WHAT" box: Cashier = in the system (alerts button and product badges); Owner = in the system and one daily email (Gmail).

## Daily email (owner)
- Artisan command `jeanrose:send-daily-alerts`, scheduled daily at `ALERT_EMAIL_TIME` (D17, 07:00 Asia/Manila) in `routes/console.php`.
- Recipients: every `user_account` with role OWNER and `is_active = 1`.
- Content: open rows with `emailed_at IS NULL`. Subject "Jean Rose: stock and expiry alert". One line per row: "Vitamins: LOW STOCK, qty 2, expires 06/01/2027".
- After a successful send, set `emailed_at = now()` on those rows. If there are no such rows, send nothing.
- Local dev: run `php artisan schedule:work` in a second terminal, or run the command by hand, and check Mailpit at http://localhost:8025.
- Production: one cron line `* * * * * php /path/artisan schedule:run`.

## Tests
- Selling down to the reorder level creates one LOW STOCK row; selling more changes it to OUT OF STOCK (old row resolved, one new row).
- Restocking back to OKAY resolves the open row.
- Running sync twice creates no duplicate.
- The email command sends once, sets `emailed_at`, and a second run sends nothing.
- Cashier sees `/alerts`; VIEW links respect the owner-only rule.
