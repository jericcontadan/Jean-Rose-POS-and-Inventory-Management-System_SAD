# Design tokens (from Jean-Rose-POS-Mockup.html)

Taken from the team's mockup. This project uses Bootstrap 5 from a CDN (no npm, no Vite), so:
- Put these values as CSS variables in **`public/css/jeanrose.css`**, loaded in the layout **after** the Bootstrap CDN link.
- In the same file, override Bootstrap's variables so its components take the Jean Rose look, for example
  `--bs-primary: #D1177E; --bs-primary-rgb: 209,23,126; --bs-body-bg: #FFF0F7; --bs-body-color: #3B0A2A; --bs-border-color: #F5B5D6; --bs-border-radius: 12px;`
  and restyle `.btn-primary`, `.btn-outline-primary`, `.form-control`, `.card`, `.badge` and `.nav-pills` to match the mockup.
- Add small shared classes for things Bootstrap does not have: `.jr-badge-low`, `.jr-badge-out`, `.jr-badge-near`, `.jr-badge-ok`, `.jr-total-box`, `.jr-wordmark`.
- Views use Bootstrap classes plus these classes. Do not repeat hex codes or inline styles in views.

## Colors
| Token | Value | Used for |
|---|---|---|
| `--jr-primary` | `#D1177E` | Main buttons (Log in, Pay now, Save), active nav tab, prices, wordmark |
| `--jr-primary-text` | `#FFFFFF` | Text on main buttons |
| `--jr-primary-soft` | `rgba(209, 23, 126, 0.12)` | Focus ring, subtle highlights |
| `--jr-text` | `#3B0A2A` | Main text and headings |
| `--jr-text-strong` | `#8A0F55` | Outline button text, Alerts button text, links |
| `--jr-muted` | `#7A3F63` | Labels, hints, icons |
| `--jr-bg` | `#FFF0F7` | Page background, input fill |
| `--jr-panel` | `#FFFFFF` | Cards, header bar, panels |
| `--jr-fill` | `#FFE3F1` | Order lines, Alerts button, table header, total box |
| `--jr-border` | `#F5B5D6` | Card, input and header borders (2 px) |
| `--jr-border-soft` | `#FBD3E8` | Table row lines, dashed dividers |
| `--jr-danger` | `#8E0F3E` | Out of stock badge, Confirm void, filled Void button |
| `--jr-error` | `#B00020` | Form error text and error input border |
| `--jr-near-expiry-bg` / `-text` | `#FFE08A` / `#5A3B00` | NEAR EXPIRY badge |
| `--jr-low-bg` / `-text` | `#FFD0B0` / `#6B2D00` | LOW STOCK badge, "rows have errors" box |
| `--jr-ok-bg` / `-text` | `#D3F3EF` / `#0A5F63` | OK badge, NEW STOCK box, "rows are ready" box, discount line text |

## Type
| Item | Value |
|---|---|
| Body font | Nunito (400, 600, 700, 800), 16 px |
| Headings and buttons | Poppins (500, 600, 700) |
| Wordmark "Jean Rose" | Parisienne, 50 px, `--jr-primary`; under it "BEAUTY PRODUCTS TRADING" in Poppins 600, 10 px, letter-spacing 2.6 px |
| Page title | Poppins 700, about 32 px |
| Total amount | Poppins 800, about 36 px, `--jr-primary` |

Load the three fonts from Google Fonts (one `<link>` in the layout, next to the Bootstrap CDN link), and set `--bs-body-font-family` to Nunito.

## Shape
| Item | Value |
|---|---|
| Card radius | 24 px, 2 px `--jr-border`, soft pink shadow |
| Button and input radius | 12 px (search box and pill buttons: 24 px, fully round) |
| Button and input height | 48 px (never below 44 px) |
| Badge | pill, 12 px bold text, uppercase for statuses |
| Page padding | 32 px; header 12 px × 32 px, white, 2 px bottom border |

## Assets
- `assets/logo.png`: full logo used on the login page (left half, on `--jr-primary` background). Copy it to `public/images/logo.png`.
- Product images in the mockup are simple drawn icons. Real products use `product.image_path`; use a pink placeholder icon when there is none.

## Screens
| Mockup file (`docs/design/`) | HTML (`docs/design/screens/`) | Wireframe screens it covers |
|---|---|---|
| `01-login.png` | `01-login.html` | 1 |
| `02-forgot-reset-password.png` | `02-forgot-reset-password.html` | 2 |
| `03-pos-cashier.png` | `03-pos-cashier.html` | 3, and 5 (cashier view) |
| `04-payment-receipt.png` | `04-payment-receipt.html` | 4 |
| `05-inventory-owner.png` | `05-inventory-owner.html` | 9, and 5 (owner nav) |
| `06-stock-price-product-import.png` | `06-stock-price-product-import.html` | 10, 12, 11 (import side) |
| `07-sales-records-void.png` | `07-sales-records-void.html` | 7, 8 |
| `08-alerts-daily-email.png` | `08-alerts-daily-email.html` | 6 |
| `09-audit-log-export.png` | `09-audit-log-export.html` | 13, 11 (export side) |
| `10-access-denied-session-expired.png` | `10-access-denied-session-expired.html` | 14 |

The HTML files are static copies of the mockup with inline styles. Read them for exact sizes, spacing and colors. They are reference only: do not copy them into `resources/views` as they are; rebuild them as Blade with Bootstrap and the classes above.

## Where the mockup differs from the wireframes
The mockup is the newer design. Follow it for these points. The rules in `docs/04-business-rules.md` still apply.

| Screen | Mockup | What to build |
|---|---|---|
| Menu (5) | No side menu. Header bar: wordmark, nav tabs (owner only), Alerts button with count, "Signed in as …", Log out | Header nav with `.nav-pills`. Cashier has no tabs. Below about 900 px the tabs collapse into a menu button (tap, not hover). |
| POS (3) | One "Cancel / Void" button. Each order line has − / + and Remove. No near-expiry filter tab | "Cancel / Void" clears the unpaid order after a confirm. − / + change quantity, Remove deletes the line. The near-expiry badge on each card is enough. |
| Payment (4) | Quick cash buttons: "Exact ₱558.00", "₱600", "₱1,000" | Exact total, next ₱100 up, next ₱1,000 up. They only fill the Cash received box. |
| Inventory (9) | Summary cards (Low stock, Out of stock, Near expiry). Update stock, Update price and a trash icon on every row. No Close button | Row buttons instead of selecting a row. Trash = Delete Row (only if no history). |
| Stock, price, add product, import (10, 11, 12) | Shown as cards over Inventory | Build each as its own page with the card centered. |
| Sales Records and Void (7, 8) | Void button on each row; void card on the right of the same page | Rows are items, but a void cancels the whole sale. Group rows by sale (receipt no. and time) and show Void once per sale. The mockup's sample rows and sample void card are different sales; that is only sample data. |
| Audit Log (13) | Export card on the right of the page | The export card has: Inventory list / Sales Records / Audit log, From and To dates, Download .xlsx. |
| Alerts (6) | Left card is the page. Right side explains badges and the email | Build `/alerts` from the left card. Use the right side as the template for the daily email. |
