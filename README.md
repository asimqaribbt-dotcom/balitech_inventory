# BALITECH Pvt. Ltd. — IT Inventory Management System

A complete, professional and responsive **IT Asset & Inventory Management** web application
built with **core PHP 8+, MySQL, HTML5, CSS3, JavaScript, Bootstrap 5 and Font Awesome**.
No frameworks (no Laravel / CodeIgniter), no Node.js, no React — it runs directly on
**XAMPP** or any standard shared PHP host.

---

## Table of contents

1. [Requirements](#1-requirements)
2. [What is included](#2-what-is-included)
3. [Project structure](#3-project-structure)
4. [XAMPP installation instructions](#4-xampp-installation-instructions)
5. [Default administrator account setup](#5-default-administrator-account-setup)
6. [Sign in & roles](#6-sign-in--roles)
7. [Modules at a glance](#7-modules-at-a-glance)
8. [Reports & export](#8-reports--export)
9. [Security features](#9-security-features)
10. [Deploying to shared hosting](#10-deploying-to-shared-hosting)
11. [Troubleshooting](#11-troubleshooting)
12. [Customisation](#12-customisation)

---

## 1. Requirements

| Component | Minimum |
|-----------|---------|
| PHP       | 8.0 or newer (tested up to 8.3) |
| MySQL     | 5.7+ / MariaDB 10.4+ |
| Web server| Apache 2.4 (XAMPP / WAMP / LAMP / shared hosting) |
| Extensions| `pdo_mysql`, `mbstring`, `json`, `openssl` (all enabled by default in XAMPP) |
| Browser   | Any modern browser (Chrome, Edge, Firefox, Safari) |

---

## 2. What is included

- Professional **navy / white / light-grey** corporate admin UI with a collapsible sidebar.
- **Login system** with secure password hashing, sessions and CSRF protection.
- **Role-based access control** (Super Admin, IT Admin, Branch IT Staff, Department Manager).
- **Dashboard** with 12 live database statistics, 4 charts and activity/alerts panels.
- **Inventory** module: add / view / edit / delete / search / filter / details / assign /
  transfer / update status, plus CSV export.
- **Masters**: branches, departments, employees, categories and brands.
- **Assignments & Transfers** with complete history.
- **Stock management**: stock in, stock out, low-stock alerts and stock history.
- **Maintenance** module: fault reporting, repair records, costs and status tracking.
- **Reports** (9 types) with filters and **CSV + printable PDF** export.
- **Global search**, user management, activity log (audit trail), profile & change password.
- Complete **MySQL database script** with realistic sample data.

---

## 3. Project structure

```
balitech_inventory/
├── api/                     AJAX/JSON endpoints (cascading selects, search suggestions)
├── assets/
│   ├── css/style.css        Design system (all styling)
│   ├── js/app.js            Sidebar, filters, cascades, validation, confirmations
│   └── img/favicon.svg
├── assignments/             Asset assignment & return
├── branches/                Branch management
├── brands/                  Brand master
├── categories/              Asset category master (2-level)
├── config/
│   ├── config.php           DB credentials, paths, session hardening
│   └── database.php         PDO singleton + query helpers
├── database/
│   └── balitech_inventory.sql   Full schema + sample data
├── departments/             Department management
├── employees/               Employee management
├── includes/
│   ├── auth.php             Login, RBAC ability matrix, data scoping
│   ├── functions.php        Shared helpers (escaping, CSRF, pagination, badges…)
│   ├── header.php           Top bar + head
│   ├── sidebar.php          Navigation
│   └── footer.php           Scripts + closing tags
├── inventory/               Core asset register
├── maintenance/             Maintenance records
├── reports/                 All reports + shared CSV/PDF exporter
├── stock/                   Stock in / out / history
├── transfers/               Branch-to-branch transfers
├── users/                   User accounts (Super Admin only)
├── activity_logs.php        Audit trail
├── change_password.php
├── dashboard.php            Main dashboard
├── forgot_password.php      Password reset guidance
├── index.php                Entry point (redirects)
├── init.php                 Bootstrap file (required by every page)
├── login.php                Branded login screen
├── logout.php
├── profile.php
├── search.php               Global search
├── setup.php                One-time installer for default accounts
└── README.md                This file
```

---

## 4. XAMPP installation instructions

### Step 1 — Install XAMPP

1. Download XAMPP (PHP 8.0+) from <https://www.apachefriends.org/download.html>.
2. Install it (default location is `C:\xampp`).
3. Open the **XAMPP Control Panel** and press **Start** next to **Apache** and **MySQL**.
   Both must show a green "Running" state.

### Step 2 — Copy the project into the web root

1. Copy the whole `balitech_inventory` folder into the XAMPP web root:

   ```
   C:\xampp\htdocs\balitech_inventory
   ```

   > The final path must be `C:\xampp\htdocs\balitech_inventory\index.php`.
   > You may rename the folder, but if you do, the URL in Step 4 changes accordingly.

### Step 3 — Create and import the database

1. Open **phpMyAdmin**: <http://localhost/phpmyadmin>
2. Click **New** in the left sidebar.
3. Database name: **`balitech_inventory`**
4. Collation: **`utf8mb4_unicode_ci`**
5. Click **Create**.
6. Select the new `balitech_inventory` database in the left sidebar.
7. Go to the **Import** tab.
8. Click **Choose File** and select:

   ```
   C:\xampp\htdocs\balitech_inventory\database\balitech_inventory.sql
   ```

9. Leave all options at their defaults and click **Go** (bottom right).
10. You should see *"Import has been successfully finished…"*.

   > The SQL file creates the database itself if it does not exist, so you may also simply
   > import it from the phpMyAdmin home page without creating the database first.

### Step 4 — Check configuration (optional)

The default settings in `config/config.php` already match a stock XAMPP install:

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'balitech_inventory');
define('DB_USER', 'root');
define('DB_PASS', '');          // XAMPP default MySQL password is empty
```

If you set a MySQL `root` password (or use a different user), edit those four lines.
For shared hosting, use the database name / user / password supplied by your host.

### Step 5 — Create the default accounts

Open in your browser:

```
http://localhost/balitech_inventory/setup.php
```

Then follow [Section 5](#5-default-administrator-account-setup) below.

### Step 6 — Sign in

```
http://localhost/balitech_inventory/login.php
```

Use one of the accounts created in Step 5 (default password `Admin@123`).

---

## 5. Default administrator account setup

The database script ships with tables but **passwords must be generated by PHP**, so the
accounts are created by the one-time installer `setup.php` (it uses `password_hash()`).

1. Open **`http://localhost/balitech_inventory/setup.php`**.
2. The page runs an **environment check** and confirms the connection to the
   `balitech_inventory` database and that all 12 required tables are present.
   - If tables are missing it tells you to import `database/balitech_inventory.sql` first.
3. The **default password** field is pre-filled with `Admin@123`.
   You may change it before installing (minimum 8 characters).
4. Click **Install default accounts**.
5. The following four accounts are created / reset:

   | Username      | Role                | Email                      | Default password |
   |---------------|---------------------|----------------------------|------------------|
   | `superadmin`  | Super Admin         | admin@balitech.com         | `Admin@123`      |
   | `itadmin`     | IT Admin            | it.admin@balitech.com      | `Admin@123`      |
   | `branchstaff` | Branch IT Staff     | branch.staff@balitech.com  | `Admin@123`      |
   | `deptmanager` | Department Manager  | sales.manager@balitech.com | `Admin@123`      |

6. **IMPORTANT — secure the installation:**

   - **Delete `setup.php`** from the server (`C:\xampp\htdocs\balitech_inventory\setup.php`).
   - Sign in as **`superadmin`** and immediately open
     **Change password** (bottom of the sidebar / user menu) to replace `Admin@123`.
   - The first successful run writes a lock file `config/installed.lock`. While that file
     exists, `setup.php` will only *reset* the passwords of the default accounts — it will
     not create anything new. Deleting `setup.php` is still strongly recommended.

> Re-running `setup.php` is safe for a fresh install (it updates existing default accounts
> instead of duplicating them).

---

## 6. Sign in & roles

| Role | Access |
|------|--------|
| **Super Admin**  | Full access to everything: all branches, inventory, masters, users, activity log and all reports. |
| **IT Admin**     | Manage inventory, assignments, transfers, stock, maintenance, employees, departments, branches, and view reports. |
| **Branch IT Staff** | Inventory, assignments, transfers, stock, maintenance and reports — **scoped to their own branch**. |
| **Department Manager** | Read-only reporting — sees only the assets of **their own department**. |

Users are managed from **Users** in the sidebar (Super Admin only). Each user is attached to
a branch and/or department, which drives the automatic data scoping above.

---

## 7. Modules at a glance

- **Dashboard** — 12 live KPIs (total assets, branches, departments, employees, assigned,
  available stock, under maintenance, damaged, PCs, monitors, headsets, networking),
  inventory overview / branch-wise / department-wise charts, recent activity, recently
  added equipment and maintenance alerts.
- **Inventory** — add, view, edit, delete, search, filter, view details, assign to employee,
  transfer between branches, update status, export CSV. Each asset carries: auto-generated
  Asset ID (e.g. `BLT-00061`), category, equipment name, brand, model, serial number, branch,
  department, assigned employee, purchase date, purchase price, warranty expiry, condition,
  status, remarks and date added.
- **Categories & Brands** — full 6-group IT taxonomy (Computers; Monitors & LCDs;
  Accessories; Networking Equipment; Networking Tools; Other IT Equipment) with all
  sub-categories. Administrators can add custom categories and brands.
- **Branches** — CRUD, details page, branch inventory and branch-wise statistics.
- **Departments** — CRUD, department inventory and department-wise reports.
- **Employees** — CRUD, details, assigned equipment, transfer and return equipment.
- **Assignments** — assign assets to employees, record assignment date, assigned-by,
  return date and status; full history.
- **Stock** — available / new / unassigned equipment, low-stock alerts, stock in, stock out,
  transfers and stock history.
- **Maintenance** — report faults, add repair records, repair history, cost, technician,
  repair date, status and condition.
- **Global search** — search across Asset ID, serial, brand, model, equipment name, employee,
  branch and department from the top bar or `search.php`.

Asset statuses: **In Use (assigned) · Available · Under Maintenance · Damaged · Retired**.

---

## 8. Reports & export

Nine reports are available under **Reports** with on-screen filters:

1. Complete Inventory
2. Branch-wise Inventory
3. Department-wise Inventory
4. Employee-wise Assets
5. Category-wise Inventory
6. Available Stock
7. Maintenance
8. Damaged Equipment
9. Equipment Transfer

Every report can be exported via the shared exporter:

```
reports/export.php?report=<name>&format=csv|pdf&<filters…>
```

- **CSV** — opens directly in Microsoft Excel (UTF-8 BOM included).
- **PDF** — opens a print-optimised page; use the browser's *Print → Save as PDF*.

---

## 9. Security features

- **PDO prepared statements** everywhere (emulation disabled) — SQL-injection safe.
- **`password_hash()` / `password_verify()`** (bcrypt) for all passwords.
- **CSRF tokens** on every POST form (`csrf_field()` / `csrf_verify()` with `hash_equals`).
- **Session hardening**: custom session name, `HttpOnly`, `SameSite=Lax`, periodic
  `session_regenerate_id()`, session fixation prevention on login.
- **Role-based access control** plus row-level data scoping per branch / department.
- **XSS prevention** — all output escaped through a single `e()` helper.
- **Input validation** and **POST-only destructive actions** (delete / status changes).
- **Audit trail** — significant actions are written to `activity_logs`.
- Server errors are logged, never printed to the browser.

---

## 10. Deploying to shared hosting

1. Upload the `balitech_inventory` folder (or its contents) to `public_html`.
2. Create a MySQL database and user in your host's control panel (cPanel → MySQL Databases).
3. Import `database/balitech_inventory.sql` through cPanel → phpMyAdmin.
4. Edit `config/config.php` and set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` to the values
   provided by your host.
5. Open `https://your-domain.com/setup.php` once, install the accounts, then **delete
   `setup.php`**.

The application auto-detects its base URL, so it works whether it is at the domain root or
in a sub-folder — no path changes are required.

---

## 11. Troubleshooting

| Problem | Solution |
|---------|----------|
| *"Database connection failed"* | Ensure **MySQL** is running in XAMPP and that the credentials in `config/config.php` are correct. |
| *"Table … doesn't exist"* | Import `database/balitech_inventory.sql` into the `balitech_inventory` database. |
| Login says *"No user accounts were found"* | Run `setup.php` once to create the default accounts. |
| Port **80** already in use (Apache won't start) | In XAMPP, change Apache to port 8080, then browse `http://localhost:8080/balitech_inventory/`. |
| MySQL won't start / port **3306** busy | Stop other MySQL/SQL services, or change the MySQL port and update `DB_HOST`. |
| Blank page / white screen | Check `C:\xampp\apache\logs\error.log`. Errors are logged (not displayed). |
| Charts not rendering | An internet connection is required for the Chart.js CDN, or host Chart.js locally. |

---

## 12. Customisation

- **Colours / theme** — edit the CSS variables at the top of `assets/css/style.css`
  (`--navy-800`, `--brand`, `--gray-bg`, …).
- **Number of rows per page** — `ITEMS_PER_PAGE` in `config/config.php`.
- **Application name / company** — `APP_NAME`, `APP_FULL_NAME`, `APP_COMPANY` in
  `config/config.php`.
- **Timezone** — `APP_TIMEZONE` in `config/config.php` (default `Asia/Karachi`).
- **Categories / brands / branches / departments** — add them from the UI; no code changes
  are required.

---

© BALITECH Pvt. Ltd. — IT Inventory Management System v1.0.0
#   b a l i t e c h _ i n v e n t o r y  
 