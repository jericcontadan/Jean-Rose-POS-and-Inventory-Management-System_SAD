# Step 1: Login, menu, Access Denied, Session Expired

Wireframes: screens 1, 5 and 14 in `docs/wireframes/Jean_Rose_POS_Wireframes_v4.pdf`. SRS FR-01, FR-02, Form A.

Mockup: `docs/design/01-login.png`, `10-access-denied-session-expired.png`, and the header in `05-inventory-owner.png` (owner) and `03-pos-cashier.png` (cashier).

## Login (`/login`, all users)
- Fields: Username, Password (with a show/hide eye button). Button "Log in". Link "Forgot password?" (goes to `/forgot-password`; until Step 11 it can show "Coming soon").
- Mockup: left half is the logo `logo.png` on the pink primary background with "POS and Inventory System" under it; right half is a white card with "Welcome back" and "Log in to start selling."
- Rules:
  - Only `is_active = 1` accounts can log in. For a wrong username, wrong password or inactive account, show one message: "Invalid username or password." Do not reveal which part was wrong.
  - Throttle: 5 failed tries per minute per username and IP (Laravel `RateLimiter`). Then show "Too many attempts. Try again in N seconds."
  - On success: regenerate the session, write `audit_log` (action LOGIN, module SALES, reference null, details "Signed in"), redirect to `/pos` for both roles.
- `POST /logout`: log out, invalidate the session, regenerate the CSRF token, redirect to `/login`. Not written to the audit log.
- A logged-in user who opens `/login` is sent to `/pos`.

## Menu by account type (all pages)
- Mockup: no side menu. The white header bar holds the wordmark, then the nav tabs (pill buttons, active tab filled pink), then Alerts button with count, "Signed in as **Owner**", and an outlined "Log out" button.
- OWNER tabs: POS, Sales Records, Inventory, Audit Log.
- CASHIER: no tabs (POS only). The POS page shows the search box in the header instead.
- Below about 900 px wide, the tabs collapse into a menu button (tap to open, not hover).

## Owner-only rule (server side)
- Middleware `owner` (alias in `bootstrap/app.php`): if the user is not OWNER, return HTTP 403 with the Access Denied page.
- Apply it to every route under `/sales`, `/inventory`, `/products`, `/imports`, `/exports`, `/audit-log`.
- All app routes except `/login`, password recovery and `/health` require `auth`.

## Access Denied (403)
- `resources/views/errors/403.blade.php`: big "403", "Access denied", "This page is for the owner only.", "Your account can use the POS screen.", button BACK TO POS.

## Session Expired
- `SESSION_LIFETIME=30` (minutes of inactivity, D14).
- When a logged-out user (session timed out) opens an app page, redirect to `/login` and show the Session Expired message: "You were logged out because of inactivity. Please log in again." with a LOG IN AGAIN button.
- A form posted after the session expired (HTTP 419) shows the same Session Expired page, not Laravel's default 419 page.

## Tests
- Active owner and cashier can log in and land on `/pos`; login writes one `audit_log` LOGIN row.
- Wrong password and inactive account both fail with the same message, and write no audit row.
- Sixth failed attempt in a minute is throttled.
- Cashier gets 403 on `/sales`, `/inventory`, `/audit-log`; owner gets 200 (or a placeholder page for now).
- Guest is redirected to `/login` from `/pos`.
- Logout ends the session.
- Menu shows four links for owner and only POS for cashier.
