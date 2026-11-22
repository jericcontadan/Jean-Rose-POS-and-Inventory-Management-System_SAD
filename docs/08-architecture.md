# 08 - Architecture (cloud and application)

Two views: where the system runs (cloud) and how the code is organized (application). Claude Code must follow both.

## A. Cloud architecture (capstone version, Azure)
Picture: `docs/architecture/cloud-architecture.png` (first AWS plan; the diagram below shows the live Azure server)

```mermaid
flowchart LR
  U["Owner / Cashier<br/>tablet or PC browser"] -->|HTTPS 443| N
  subgraph AZ["Azure"]
    subgraph VM["Ubuntu VM (80, 443 open; SSH with keys only)"]
      N["Nginx + PHP-FPM<br/>Laravel POS"]
      DB[("MySQL 8<br/>jeanrose_pos")]
      DS["scripts/deploy.sh"]
    end
  end
  subgraph OLD["From the first AWS plan (Azure replacement to be decided)"]
    S3A["S3: product images"]
    S3B["S3: backups"]
    CW["CloudWatch + SNS<br/>alarms by email"]
  end
  N -->|SQL 3306| DB
  N --> S3A
  DB -->|nightly dump| S3B
  DS -->|update code, reload PHP| N
  N -.-> CW
  N -->|"HTTPS REST API"| BR["Brevo Transactional Email API<br/>reset link + daily alert"]
  N -.->|"SMTP backup if Brevo fails"| GM["Gmail SMTP"]
  N -.->|"HTTPS API (planned)"| SMS["Semaphore SMS<br/>6-digit reset code"]
  GH["GitHub repo + Actions<br/>Tests, then Deploy"] -->|"SSH (only if tests pass)"| DS
```

| Part | Decision |
|---|---|
| Server | One small Azure VM (Ubuntu), Nginx + PHP 8.3 FPM, Laravel in `/var/www/jeanrose-pos` |
| Database | MySQL 8 on the same server, not open to the internet. Only the app connects, using `pos_user` |
| HTTPS | Free Let's Encrypt certificate on Nginx, HTTP redirects to HTTPS |
| Firewall | internet -> app (80, 443). SSH (22) with keys only; GitHub Actions needs it to deploy. 3306 closed to the internet |
| Secrets | App secrets only in the server `.env`. The 3 deploy secrets (`DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`) only in GitHub Actions secrets. Never in files |
| Deploy (CI/CD) | Merge to `main` -> Tests workflow -> if it passes, Deploy workflow -> SSH -> `scripts/deploy.sh`. One deploy at a time, never runs migrations or SQL. Redeploy and rollback: `docs/07-deployment.md` (section 9) |
| Backups | Daily database backup kept 7 days plus nightly dump to S3 |
| Monitoring | CloudWatch alarms (CPU, status) with email alerts |
| Not used (cost) | Load balancer, NAT Gateway, Docker, Multi-AZ. Can be added later |
| Local development | Laragon (PHP 8.3, MySQL 8.4, Composer) on each laptop |

## B. Application architecture (Laravel)
Style: classic server-rendered MVC with a thin controller and a service layer. Blade + Bootstrap 5 CDN, no Vite or npm.

```
request -> routes/web.php -> middleware (auth, can:owner, throttle)
        -> Controller (thin: validate with FormRequest, call a service, return a view or redirect)
        -> Service (business rules, DB::transaction)
        -> Eloquent Models -> MySQL (jeanrose_pos)
        -> Blade view (Bootstrap) -> browser
```

### Folder layout
```
app/
  Http/
    Controllers/    AuthController, PasswordResetController, PosController, PaymentController,
                    ReceiptController, AlertController, SalesRecordController, InventoryController,
                    ProductController, ExportController, ImportController, AuditLogController, HealthController
    Middleware/     (use Gate 'owner' via can:owner; no custom RBAC)
    Requests/       LoginRequest, PayRequest, VoidRequest, ProductRequest, StockRequest, PriceRequest, ImportRequest
  Models/           UserAccount, Category, Supplier, Product, ProductBatch, Inventory, InventoryHistory, Sale, SaleItem, AuditLog,
                    AlertNotification
  Services/         SaleService, InventoryService, StockStatusService, BatchPickerService, AuditService, AlertService, BrevoMailService,
                    (Sms - planned)
  Exports/          SalesExport, InventoryExport, AuditLogExport
  Imports/          ProductsImport (planned)
  Mail/             DailyStockAlertMail, PasswordResetMail
  Console/Commands/ RefreshStockStatus, SendDailyAlertEmail
config/pos.php      near_expiry_days, near_expiry_discount_percent, mail_via (brevo|smtp) (from .env)
config/services.php brevo.key (BREVO_API_KEY from .env)
routes/web.php      guest group, auth group, owner group (can:owner)
routes/console.php  schedule: RefreshStockStatus 06:00, SendDailyAlertEmail 08:00 (Asia/Manila)
resources/views/    layouts/app.blade.php (viewport, Bootstrap CDN, role-based menu), auth/, pos/, receipts/,
                    sales/, inventory/, products/, alerts/, audit/, errors/403.blade.php, partials/
tests/Feature/      LoginTest, RolesTest, PosTest, PayNowTest, VoidTest, StockStatusTest, InventoryTest, AuditLogTest
scripts/deploy.sh   update script that runs on the server (called by GitHub Actions)
.github/workflows/  tests.yml (every push and pull request), deploy.yml (after Tests passes on main)
docs/               these files
```

### Layer rules
1. **Controllers are thin.** No business rules, no raw SQL.
2. **Only services change stock.** Every change to `inventory.quantity_on_hand` goes through `InventoryService` so that
   `stock_status` and `inventory_history` stay consistent.
3. **`SaleService`** has `pay()` and `void()`. Each runs in one `DB::transaction()` and uses `BatchPickerService` (FEFO),
   `StockStatusService` (status) and `AuditService` (audit rows).
4. **`StockStatusService`** is the only place with the status rule (OUT OF STOCK > NEAR EXPIRY > LOW STOCK > OKAY). After each refresh
   it calls `AlertService::sync()`, the only code that writes `alert_notification`.
5. **`AuditService::log(user, action, module, ref, details, saleId = null)`** is the only way to write `audit_log`.
6. **Models** map tables only (see `02-database-schema.md`), with relationships, no business logic.
7. **Owner-only** routes live in one route group with `can:owner`; the Gate is defined once in `AppServiceProvider`.
8. **Validation** in FormRequests. **Views** only show data; no queries in Blade.
9. External services (Brevo, Gmail, Semaphore) are called from services or Mailables, never from views or controllers directly.
10. **Every email goes through `BrevoMailService::send(to, mailable)`.** It posts the rendered Mailable to the Brevo
    Transactional Email API (`POST https://api.brevo.com/v3/smtp/email`). On any non-201 answer or a timeout it logs the
    status and Brevo's message (never the key) and sends the same Mailable through Gmail SMTP. Details: `docs/api/brevo-integration.md`.

### Pay Now flow
```mermaid
sequenceDiagram
  participant C as Cashier (tablet)
  participant PC as PaymentController
  participant SS as SaleService
  participant BP as BatchPickerService
  participant IS as InventoryService
  participant AU as AuditService
  participant DB as MySQL
  C->>PC: POST /pos/pay (cart, cash received)
  PC->>PC: PayRequest validation
  PC->>SS: pay(user, cart, cash)
  SS->>DB: BEGIN
  SS->>BP: pick batches (FEFO, not expired)
  SS->>DB: insert sale + sale_item (discount if near expiry)
  SS->>IS: reduce stock, refresh status, write history (SALE)
  SS->>AU: log SALE
  SS->>DB: COMMIT (or ROLLBACK on any error)
  SS-->>PC: sale
  PC-->>C: redirect to /receipts/{sale}
```

### Naming and style
Controllers `XxxController`, services `XxxService`, tests `XxxTest`. Routes named (`pos.index`, `pos.pay`, `sales.void`, ...).
Short comments in plain English. One feature per branch (`feature/<name>`).
