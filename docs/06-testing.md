# 06 - Testing, test log and bug log

The rubric grades Functionality and Testing (30%): core use cases work, test logs show bugs and fixes, API response testing.

## How we test
- **Feature tests** (Laravel) in `tests/Feature`, using the `DatabaseTransactions` trait so each test rolls back.
  Use a separate database `jeanrose_pos_test` created from `docs/sql/jeanrose_pos_test.sql` (set it in `phpunit.xml` or `.env.testing`),
  so tests never touch your working data. Run: `php artisan test`.
- **GitHub Actions** runs the same tests on every push against a throwaway MySQL built from `docs/sql/jeanrose_pos_test.sql`.
- **Postman**: one collection with the main requests. Save request, expected result, actual result and a screenshot.
- **Manual tests** on a real tablet over HTTPS (login, Pay Now, print, keyboard types).
- Mock external services in tests (`Mail::fake()`, `Http::fake()`) so no real email or SMS is sent.

## Core test cases (write these first)
| ID | Test | Expected |
|---|---|---|
| T01 | Log in with valid owner | Redirect to Dashboard, LOGIN audit row (cashier: redirect to POS) |
| T02 | Log in with wrong password | Generic error, no session |
| T03 | Log in with inactive account | Blocked |
| T04 | Cashier opens `/dashboard`, `/inventory`, `/sales`, `/audit-log`; owner opens `/pos`, cart or `/pos/pay` | 403 |
| T05 | Add item to cart, change quantity | Total updates |
| T06 | Add out-of-stock product | Refused |
| T07 | Pay Now with enough cash | Sale PAID with `cash_received` and `change_amount` saved, stock reduced, history SALE, audit SALE |
| T08 | Pay Now with cash less than total | Refused, nothing saved |
| T09 | Pay Now where stock changes mid-sale | Rolled back, error shown |
| T10 | Near-expiry item in cart | 10% discount on that line |
| T11 | Two batches, earliest expiry first | FEFO order, split rows |
| T12 | Void a PAID sale with reason | CANCELLED, `void_reason`, `voided_at`, `voided_by` saved, stock returned, history VOID, audit VOID |
| T13 | Void without reason or twice | Refused |
| T14 | Stock status rules | OUT OF STOCK > NEAR EXPIRY > LOW STOCK > OKAY |
| T15 | Sales Records by date | Correct rows and totals |
| T16 | Price change | Past sales keep old price, audit row with old and new |
| T17 | Delete product that has sales | Refused with message |
| T18 | Audit Log is read-only | No edit or delete route |
| T19 | Password reset (Gmail) | Mail sent once, link works once, expires |
| T20 | Excel export | File downloads with correct rows (owner only) |
| T21 | Daily alert email | Sent only when un-emailed alerts exist, to owners; `emailed_at` set; second run sends nothing |
| T23 | Alert tracking | Low stock creates one open `alert_notification` row; no duplicate on repeat; restock to OKAY sets `resolved_at` |
| T24 | Brevo email API (`BrevoMailTest`) | 201 sends by Brevo only (messageId logged); 401, 500, timeout fall back to Gmail SMTP; request has URL, `api-key` header, JSON body; reset and daily alert use it; both failing keeps the same reset message and `emailed_at` empty |
| T25 | Owner Dashboard (`DashboardTest`) | Owner lands on `/dashboard`; today's numbers equal the Sales Records summary; voids, refund and Senior/PWD counted; cashier gets 403 |
| T22 | Tablet check | Layout fits 1024 and 1280 px, buttons usable by touch |

## Test log (copy rows here as you test)
| Date | Test ID | Tester | Steps | Expected | Actual | Pass/Fail | Evidence |
|---|---|---|---|---|---|---|---|
| | | | | | | | |

## Bug log
| Bug ID | Date found | Found by | Screen | Description | Steps to reproduce | Severity (Low/Med/High) | Fix commit | Status (Open/Fixed/Retested) |
|---|---|---|---|---|---|---|---|---|
| B001 | | | | | | | | |

## API integration documentation (for the deliverable)
For Gmail (and Semaphore if built): what it does, which route triggers it, sample request and response, how it was tested
(Postman screenshot), and what happens on failure.
