# Step 11: Forgot and Reset Password

Wireframe: screen 2. SRS FR-03. Table `password_reset_tokens` (`email` PK, `token`, `created_at`). Build the Gmail link first, then the SMS code.

Do not use Laravel's built-in password broker as is: the user model and table names are custom. Write a small `PasswordResetService`.

Mockup: `docs/design/02-forgot-reset-password.png` ("Step 1 of 2" and "Step 2 of 2" cards; the Gmail and SMS choices are two large option cards).

## Forgot password (`GET/POST /forgot-password`, guests)
- Field: USERNAME OR EMAIL. Choice: "Email (Gmail): a one-time link" or "SMS: a 6-digit code to the phone". Buttons SEND, "Back to log in".
- Find an **active** account by username or email.
- Always show the same message, whether or not the account exists: "If the account exists, we sent the reset to its email or phone."
- Throttle: 3 requests per 15 minutes per account and IP.
- Email choice:
  - Make a random 64-character token. Store `Hash::make(token)` in `password_reset_tokens` (upsert by email, `created_at = now()`).
  - Send a Mailable with the link `/reset-password/{token}?email=...`. Subject "Jean Rose: reset your password". Valid 60 minutes (D16).
- SMS choice:
  - Only if the account has a `phone_number`. Otherwise do nothing (same message).
  - Make a 6-digit code with `random_int`. Store `Hash::make(code)` the same way. Valid 10 minutes.
  - Send through `SmsSender` (interface). `LogSmsSender` writes "SMS to 09xx: code 123456" to the log (dev). `SemaphoreSmsSender` posts to Semaphore's messages API with `SEMAPHORE_API_KEY` and `SEMAPHORE_SENDER_NAME` (check Semaphore's current API docs for the exact endpoint and fields). Choose by `SMS_DRIVER`.
  - Keep the account email in the session and go to `/reset-code`.

## Reset with link (`GET/POST /reset-password/{token}`)
- Fields: NEW PASSWORD, CONFIRM PASSWORD. Button SAVE PASSWORD.
- Valid when a row exists for the email, `Hash::check(token)` passes and it is not older than 60 minutes.

## Reset with code (`GET/POST /reset-code`)
- Fields: SMS CODE (6 digits), NEW PASSWORD, CONFIRM PASSWORD.
- Valid when the code matches and is not older than 10 minutes. Allow 5 wrong codes, then delete the row and ask the user to start again.

## On success (both)
- Password rules (D15): at least 8 characters, at least one letter and one number, confirmed.
- Update `user_account.password_hash = Hash::make(new)`. Delete the `password_reset_tokens` row (one-time use).
- Redirect to `/login` with "Password saved. Please log in."
- Never log or display the token or code (except `LogSmsSender` in local dev only, and only when `APP_ENV=local`).

## Mail settings
- Dev: Mailpit (`MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`).
- Production Gmail: `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_ENCRYPTION=tls`, the Gmail address and a Google **App Password** in `.env` only.

## Tests
- Same message for an existing and a missing account.
- Inactive account gets no token row.
- Link works once; second use fails; expired link fails.
- Code works once; wrong code 5 times deletes the row.
- Password rules enforced; new password lets the user log in; old one does not.
- No token or code appears in the response HTML.
