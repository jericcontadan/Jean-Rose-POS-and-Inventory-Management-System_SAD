# Jean Rose POS and Inventory Management System

A web-based point of sale and inventory system for **Jean Rose Beauty Products Trading**.
It handles sales at the counter, tracks stock by batch and expiry date, warns the owner
about low or expiring stock, and keeps a record of every important action.
Built for tablet and PC browsers.

Capstone project, BSIS-3C, Technological University of the Philippines – Manila.

## Live System and Test Accounts
Live site: https://jeanrosepos.eastasia.cloudapp.azure.com

| Role | Username | Password |
|---|---|---|
| Owner (admin) | `owner` | `jeanrose2026` |
| Cashier | `cashier` | `jeanrosecashier` |

These test accounts are for grading. The system uses sample data, and these passwords will be changed after grading.

## Team 8
- Salcedo, Tristan
- Contadan, Jeric
- Pascual, Megryan
- Panis, Jayson
- Olvina, Drew

## Features

**Point of Sale**
- Add products to a cart, enter the cash received, and get the change automatically
- Stock is taken from the batch that expires first (FEFO)
- Products within 30 days of expiry get a 10% discount automatically
- The owner can void a sale; the stock goes back to its batch

**Inventory**
- Products with batches, each with its own quantity and expiry date
- Stock status for every product: OUT OF STOCK, NEAR EXPIRY, LOW STOCK, or OKAY
- Stock statuses refresh every morning at 06:00

**Reports and Records**
- Sales records with details per sale
- Excel export of records
- Audit log of logins, sales, voids, inventory updates, exports, and password resets

**Email**
- Daily alert email at 08:00 listing low-stock and near-expiry products
- Password reset by email (link expires in 60 minutes)

**Roles**
- **Owner**: full access, including inventory, reports, audit log, and voids
- **Cashier**: point of sale only

## Tech Stack

| Part | Tool |
|---|---|
| Backend | Laravel 13 (PHP 8.3) |
| Frontend | Blade templates + Bootstrap 5 |
| Database | MySQL 8 (12 tables) |
| Excel | Laravel Excel |
| Email | Brevo email API, with Gmail SMTP as backup |
| Hosting | Microsoft Azure virtual machine (Ubuntu 24.04, Nginx, HTTPS) |
| CI/CD | GitHub Actions |
| Project tracking | Jira |

## Architecture
See [docs/architecture](docs/architecture/README.md) for the cloud and DevOps architecture,
diagrams, the System Design Specification (SDS), and the database schema.

## Security
- Passwords are hashed (bcrypt); login is limited to 5 tries per minute
- Owner-only pages are blocked for cashiers (403)
- Password reset tokens are hashed and expire after 60 minutes
- CSRF protection on every form
- Sales use database transactions so stock is never sold twice
- The app's database user can only SELECT, INSERT, UPDATE, and DELETE
- Secrets are kept in `.env` on the server and are never committed

## Testing and Deployment
- Every pull request runs **220 automated tests** with GitHub Actions
- When a pull request is merged into `main` and the tests pass, GitHub Actions deploys it to the Azure server automatically
- Nobody pushes directly to `main`; every change goes through a pull request

## Run It Locally (Windows, Laragon)
1. Install Laragon (Full), Git, and MySQL Workbench. Clone the repo into `C:\laragon\www`:
   ```
   git clone https://github.com/jericcontadan/Jean-Rose-POS-and-Inventory-Management-System_SAD.git jeanrose-pos
   cd jeanrose-pos
   composer install
   copy .env.example .env
   php artisan key:generate
   php artisan storage:link
   ```
2. In MySQL Workbench, run `docs/sql/jeanrose_pos.sql`, then `docs/sql/sample_data.sql`.
3. Set the database details in `.env`, then run `php artisan serve` and open http://localhost:8000.

The database is created from the SQL files in `docs/sql/`, not from Laravel migrations.

## Docs
The `docs/` folder has the overview, database schema, screens and routes, business rules,
build plan, testing, deployment, and architecture documents.
