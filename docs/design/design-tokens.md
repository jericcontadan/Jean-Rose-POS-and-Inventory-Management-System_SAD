# Design tokens (from Jean-Rose-POS-Mockup.html)

Taken from the team's mockup. Put these in `resources/css/app.css` as CSS variables and use them everywhere. Do not repeat hex codes in views.

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

Load the three fonts from Google Fonts (one `<link>` in the layout).

## Shape
| Item | Value |
|---|---|
| Card radius | 24 px, 2 px `--jr-border`, soft pink shadow |
| Button and input radius | 12 px (search box and pill buttons: 24 px, fully round) |
| Button and input height | 48 px (never below 44 px) |
| Badge | pill, 12 px bold text, uppercase for statuses |
| Page padding | 32 px; header 12 px × 32 px, white, 2 px bottom border |

## Assets
- `assets/logo.png`: full logo used on the login page (left half, on `--jr-primary` background).
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

The HTML files are static copies of the mockup with inline styles. Read them for exact sizes, spacing and colors. They are reference only: do not copy them into `resources/views` as they are; rebuild them as Blade with the CSS variables above.
