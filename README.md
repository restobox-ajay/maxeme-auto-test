# Wholesale E-commerce Platform

A comprehensive wholesale e-commerce application built with Symfony 7.4, featuring a robust admin dashboard for managing products, companies, pricing, and orders.

## Features

- **Admin Dashboard**: Centralized control center for wholesale operations.
- **Product Management**: 
    - Detailed product grid with core, pricing, and inventory management.
    - Drag-and-drop image upload with real-time preview.
    - Category hierarchy support.
- **Company Management**:
    - Manage wholesale companies with custom price lists and private SKU access.
    - Address book and user management per company.
- **Pricing Engine**:
    - Multi-tier pricing lists.
    - Default and promotional pricing support.
- **Order Management**:
    - Comprehensive order list with status tracking (Pending, Processing, Paid, etc.).
    - Manual order creation and management.
- **User Management**:
    - Role-based access control (Superadmin, Admin, Plant Staff, User).
    - Email invitation system for new users.
- **Configuration**:
    - System settings (Base currency, etc.).
    - Database-driven email templates.
    - Inventory locations management.

## Tech Stack

- **Backend**: Symfony 7.4 (PHP 8.2+)
- **Database**: SQLite (default dev) or MySQL / MariaDB (via Doctrine ORM)
- **Frontend**: Vanilla JS (ES6+), CSS3 (Custom Design System), Twig Templating
- **Mailing**: Symfony Mailer

## Installation

### Prerequisites

- PHP 8.2 or higher
- Composer
- SQLite (recommended for quick start) or MySQL / MariaDB
- A web server (Apache/WAMP, Nginx, or Symfony CLI)

### Steps

1. **Clone the repository**:
   ```bash
   git clone <repository-url>
   cd e-commerce
   ```

2. **Install dependencies**:
   ```bash
   composer install
   ```

   This also points git at the repo's own hooks (`git config core.hooksPath .githooks`), unless you
   have already set `core.hooksPath` yourself — an existing value is never overwritten. The hooks
   are warning-only: after a pull or a branch switch they tell you if the database is behind the
   migration chain. They never run `migrate` and never block anything. To set it up by hand:

   ```bash
   git config core.hooksPath .githooks
   ```

3. **Configure Environment**:
   - Duplicate `.env` to `.env.local`:
     ```bash
     cp .env .env.local
     ```
   - Choose a database in `.env.local`:
     - **SQLite (quick start)**:
       ```env
       DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db"
       ```
     - **MySQL / MariaDB**:
       ```env
       DATABASE_URL="mysql://db_user:db_password@127.0.0.1:3306/db_name?serverVersion=8.0.32&charset=utf8mb4"
       ```
   - Configure `MAILER_DSN` for email functionality, and `MAILER_FROM` with the address all
     outbound mail should come from — it is set once and applies to every message.
   - Configure domains/hosts if needed:
     - `ADMIN_HOST` (default: `admin.localhost`)
     - `CUSTOMER_HOST` (default: `127.0.0.1`)

4. **Setup Database**:
   ```bash
   # For MySQL/MariaDB only:
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate
   ```

   > The development database is intentionally **not** in version control — these two commands
   > build it from the migrations. Never commit `var/*.db`: a database file carries real
   > user rows and password hashes, and anyone who clones the repo gets working logins.

5. **Install Assets**:
   ```bash
   php bin/console assets:install public
   ```

6. **Create a Super Admin user**:
   - Create (or update) an admin user. Omit `--password` and the command prompts for it without
     echoing, so the credential stays out of your shell history:
     ```bash
     php bin/console app:create-admin --email='you@example.com'
     ```
   - Use `--update` to reset the password of an account that already exists. In CI or any other
     non-interactive context, pass `--password` explicitly and source it from your secret store —
     never commit it.

   > Choose your own password. This project intentionally ships no default credentials.

7. **Run the Application**:
   - If using Symfony CLI:
     ```bash
     symfony serve
     ```
   - Or configure your local web server (e.g., WAMP) to point to the `public/` directory.
   - Admin area is under `/admin` (host-restricted via `ADMIN_HOST`).

## Preparing this app for E2E testing

The [e2e-structured-b2b](https://github.com/axcelmediacorp/e2e-structured-b2b) suite drives this
app over real HTTP, as several personas, optionally several agents at once. Each agent needs its
**own** database so agents cannot corrupt each other's test data. This app supports that already
— it just needs switching on.

1. **Set `TEST_INSTANCE_KEY` in `.env.local`.** It's blank by default, which leaves the whole
   mechanism **off**:
   ```env
   TEST_INSTANCE_KEY=<a random string, 16+ characters>
   ```
   Generate one with `openssl rand -base64 32` or similar. Dev-only, gated in three separate
   places (`config/services_dev.yaml`, `TestInstanceConnectionFactory`), and refuses to serve a
   request under 16 characters — this is not something that can be accidentally left on in prod.

2. **Provision one SQLite database per agent:**
   ```bash
   php bin/console app:test-instance:provision --instance=sbx1
   ```
   Creates `var/db_sbx1.sqlite`, replays every migration into it, and seeds one admin + one
   customer login. **Refuses outright** if `TEST_INSTANCE_KEY` is unset or too short — it will
   not silently fall back to seeding the shared dev database.

3. **Seed it with a catalogue and orders.** Provisioning alone leaves a bare app with nothing to
   assert against. The e2e repo's `seed_demo.py` takes it the rest of the way — see its own
   README for the exact command.

4. **Clean up when an agent is done:**
   ```bash
   php bin/console app:test-instance:gc --instance=sbx1            # lists, deletes nothing
   php bin/console app:test-instance:gc --instance=sbx1 --force    # actually deletes
   php bin/console app:test-instance:gc --force                    # sweep anything untouched 24h+
   ```

Everything else — generating `personas.json`, running the suite, multiple agents, the coverage
panel — lives in the e2e repo. Start at its README's **"Preparing an app to be tested"** and
**"Running against a sandbox"** sections.

## Directory Structure

- `src/`: PHP source code (Controllers, Entities, Repositories).
- `templates/`: Twig templates (Admin dashboard, Email templates, etc.).
- `public/`: Publicly accessible files (Assets, index.php).
- `migrations/`: Database migration files.
- `config/`: Application configuration.

## Development

- **CSS**: Custom styles are located in `public/assets/css/app.css`.
- **JS**: Frontend logic is in `public/assets/js/app.js`.
- **Images**: Uploaded product images are stored in `public/uploads/products/`.

---
Built with ♥ by the Restobox (Axcel Media).
