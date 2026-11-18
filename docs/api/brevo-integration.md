# API Integration: Brevo Transactional Email API

Jean Rose POS sends all of its emails through the **Brevo Transactional Email REST API**, with **Gmail SMTP as the backup**.
Code: `app/Services/BrevoMailService.php`. Tests: `tests/Feature/BrevoMailTest.php` (T24). Postman: `docs/api/JeanRose-Brevo.postman_collection.json`.

## 1. What it does for the business
| Email | Who gets it | Why it matters to the shop |
|---|---|---|
| Password reset link (`PasswordResetMail`) | the owner or a cashier who forgot their password | They can get back into the POS without calling the developer, so selling does not stop. |
| Daily stock and expiry alert (`DailyStockAlertMail`, 08:00 Asia/Manila) | every active owner | The owner knows which products are low, out of stock or near expiry before the shop opens, so the owner can reorder in time and sell near-expiry items first. |

Why an API instead of only Gmail SMTP:
- **Proof of delivery**: every accepted email returns a `messageId`, written to `storage/logs/laravel.log`. Brevo's dashboard shows if it was delivered, opened or bounced.
- **Clear errors**: a bad key, a bad request or the daily limit comes back as an HTTP status with a message, instead of a vague SMTP error.
- **Reliability**: if Brevo fails, the same email still goes out through Gmail SMTP (fallback), so the owner never misses a reset or an alert.
- Plain HTTPS on port 443, so it works on servers where SMTP ports are blocked.

## 2. Endpoint
| | |
|---|---|
| Method + URL | `POST https://api.brevo.com/v3/smtp/email` |
| Headers | `api-key: <BREVO_API_KEY>`, `accept: application/json`, `content-type: application/json` |
| Timeout (our side) | 10 seconds |
| Sender | `Jean Rose POS <jeanrosepos@gmail.com>` (the address in `MAIL_FROM_ADDRESS`; must be a **verified sender** in Brevo) |

## 3. Authentication
Brevo uses an **API key** in the `api-key` request header (no OAuth, no login).
- The key is created in Brevo: *Profile menu > SMTP & API > API keys > Generate a new API key*.
- It is stored **only** in the server `.env` as `BREVO_API_KEY`, read through `config('services.brevo.key')`.
  It is never in GitHub, never in the Postman collection, never written to the log.
- If the key is leaked: delete it in Brevo, generate a new one, update `.env`, run `php artisan config:cache`.

## 4. Request example
```json
{
  "sender": { "name": "Jean Rose POS", "email": "jeanrosepos@gmail.com" },
  "to": [ { "email": "owner@example.com" } ],
  "subject": "Jean Rose: stock and expiry alert",
  "htmlContent": "<html><body><h2>Stock and expiry alert</h2> ... </body></html>"
}
```
`subject` comes from the Mailable's envelope and `htmlContent` is the Mailable rendered with its Blade view
(`resources/views/emails/...`), so the Brevo email and the SMTP email look the same.

## 5. Response examples
**201 Created**: the email was accepted.
```json
{ "messageId": "<202611180800.12345678901@smtp-relay.mailin.fr>" }
```
We log: `Email sent through Brevo API. {"subject":"...","messageId":"<...>"}`

**400 Bad Request**: something in the request is wrong (for example a sender that is not verified, or a missing field). The exact `message` text depends on the error; this is one example:
```json
{ "code": "missing_parameter", "message": "sender is missing" }
```

**401 Unauthorized**: the API key is wrong, deleted, or missing.
```json
{ "code": "unauthorized", "message": "Key not found" }
```

Other answers we handle the same way: **429 Too Many Requests** (rate or daily limit reached) and **5xx** (Brevo server problem).

## 6. Error handling and fallback
| What happens | What the POS does |
|---|---|
| 201 | Log `messageId`. Done, no SMTP email. |
| 400 / 401 / 429 / 5xx | Log `status`, Brevo `code` and `message` (warning). Send the same email by Gmail SMTP. |
| Timeout (10 s) or no internet | Log "Brevo API unreachable" (warning). Send the same email by Gmail SMTP. |
| Brevo **and** SMTP fail | Password reset: the unsent token is deleted, the error is logged, the page still shows the same "If that email is registered..." message (no crash, nothing revealed). Daily alert: the command logs the error and exits with code 1; `emailed_at` stays empty so the alerts go out on the next run. |

Never logged: the API key, the email body (the reset email contains the one-time reset link).

## 7. Settings (.env)
| Key | Values | Meaning |
|---|---|---|
| `BREVO_API_KEY` | the key from Brevo | Empty = Brevo is skipped, SMTP only. |
| `POS_MAIL_VIA` | `brevo` (default) or `smtp` | Switch Brevo off without removing the key. |
| `MAIL_MAILER`, `MAIL_HOST`, ... | Gmail SMTP | The backup mailer (Mailpit or `log` on a laptop). |
| `MAIL_FROM_ADDRESS` | `jeanrosepos@gmail.com` | Sender address; must be verified in Brevo. |

After changing `.env` on the server run `php artisan config:cache`.

## 8. Rate limit
- Brevo **free plan: 300 emails per day**. Our use is far below that: about 1 alert email per owner per day plus a few reset emails.
- Our own limit on reset requests (throttle in `PasswordResetController`) also protects the quota.
- If the limit is reached Brevo answers **429**, and the POS falls back to Gmail SMTP automatically.

## 9. How to test it
### Automated (no real email, no internet)
```
php artisan test --filter=BrevoMailTest
```
`Http::fake()` plays Brevo (201, 401, 500, timeout), `Mail::fake()` catches the SMTP backup, and `Http::preventStrayRequests()`
makes sure no test reaches the real Brevo. `phpunit.xml` sets `BREVO_API_KEY` to empty, so the rest of the suite never calls Brevo.
Saved run: `docs/test-logs/brevo-api.xml`.

### Postman (real call)
1. Postman > *Import* > `docs/api/JeanRose-Brevo.postman_collection.json`.
2. Open the collection > *Variables*: put your key in `brevo_api_key` (**Current value** only, so it is not saved or shared)
   and your inbox in `to_email`. Save.
3. Send **Send transactional email**. Expected: status **201 Created** and a `messageId`. The *Test Results* tab shows the checks passing.
4. Check the inbox, and Brevo > *Transactional > Logs* for the same message.
5. Negative test: change `brevo_api_key` to `wrong-key` and send again. Expected: **401** `{"code":"unauthorized","message":"Key not found"}`.
6. Negative test: remove the `sender` block from the body. Expected: **400** with a `message` that explains what is missing.

### In the app
On a laptop: *Forgot password?* with a registered email, then look in `storage/logs/laravel.log` for
`Email sent through Brevo API` with a `messageId`. The daily alert: `php artisan pos:send-daily-alerts`
(only sends if there are un-emailed alerts).
