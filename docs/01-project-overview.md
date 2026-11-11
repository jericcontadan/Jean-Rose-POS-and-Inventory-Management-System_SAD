# 01 - Project overview

## What we are building
A web app for Jean Rose Beauty Products Trading: a point of sale (POS) and inventory system.
Used in the browser of a tablet (landscape) or PC at the counter. Nothing to install.
Client: the shop owner. Team: BSIS-3C Team 4. Phase 4 (systems implementation), weeks 13 to 18.

## Users and roles (confirmed)
| Role | Can do |
|---|---|
| OWNER | Everything: POS, Sales Records, void a recorded sale, Inventory (products, batches, stock, prices), Excel import/export, Audit Log, alerts, password recovery |
| CASHIER | POS only: take orders, apply the automatic near-expiry discount, take cash payment, print receipt, see alerts, log out |
There are only two fixed account types. No role or permission management screen (Form A: RBAC is out of scope).
Accounts are created by the owner or by a developer script. There is NO public sign-up page.

## Requirements (SRS)
FR-01 Authenticate user | FR-02 Login result | FR-03 Password recovery | FR-04 Process sales transaction |
FR-05 Store sales receipts | FR-06 Manage inventory | FR-07 Stock and expiry notifications |
FR-08 Audit sales transaction | FR-09 Track audit log.

## Form A scope (assumptions we keep)
- Website-based application only.
- Only the owner can access the inventory and check sales.
- Inventory quantities are updated after every transaction.
- POS for daily sales transactions, to stop manual auditing.
- Low stock, out of stock and near expiry notification.
- POS supports the current business operation.
- Product information accuracy.
- Discount products for near expiration date.
- Maintain a stable product list and pricing structure.
- Excel integration.

## Confirmed OUT of scope (do not build)
Mobile application for customers, hardware procurement and installation, online shopping and online
transactions (no payment gateway), third-party delivery platforms, barcode hardware, cash register
interface, role-based access control.

## Release plan
- **Release 1 (core, build first):** Login, POS, Payment and Receipt, Menu by account type, Inventory (view with status),
  Sales Records, Audit Log.
- **Extras (if time allows, in this order):** automatic near-expiry discount (rule is small, do it with the POS),
  Alerts + daily Gmail email, Void a Sale, Update Stock and Price, Add or Edit Product, Forgot and Reset Password
  (Gmail link first, SMS code last), Excel export.
- **Planned (future work):** Excel import, Access Denied and Session Expired custom pages (server 403 is enough meanwhile),
  SMS reset code through Semaphore.

## External services
- Gmail (SMTP, Google App password): password reset link and ONE daily stock/expiry email to the owner.
- Semaphore SMS (optional, planned): 6-digit password reset code only. Never used for alerts (no repeated messages).
- Payments: none. Cash only.

## Handover note
The system will be deployed and passed to the shop owner. Remove demo and test accounts before go-live,
keep secrets only in the server `.env`, keep daily backups, write a user manual.
