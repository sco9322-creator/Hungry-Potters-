# Hungry Potters Operations — Setup Guide

Restaurant POS and kitchen management web app.

## What This Is

A PHP and MariaDB web application for restaurant operations:

- **POS / Front Counter** — Take orders (`new-order.php`)
- **Kitchen Display** — Track and complete orders (`kitchen.php`)
- **Orders Management** — View, edit, cancel, and record payments (`orders.php`)
- **Inventory** — Track stock, low-stock alerts, and restock (`inventory.php`)
- **Menu Management** — Add and edit dishes and recipes (`menu-management.php`)
- **User Management** — Staff accounts and roles (`user-management.php`)
- **Cashier Closeout** — Shift open/close and reconciliation (`cashier-closeout.php`)

**Users:** Admin, Cashier, Kitchen · **Tech stack:** PHP 8.2+, MariaDB 10.4, HTML/CSS/JS (Tailwind CSS via CDN)

## Requirements

1. XAMPP with Apache, MySQL/MariaDB, and PHP, or PHP 8.2+ and MariaDB 10.4+ installed separately.
2. The `hungry-potters/` project folder.
3. For an existing installation, a database backup from the old laptop.

Install XAMPP from https://www.apachefriends.org/. Confirm PHP is available:

```powershell
php --version
```

The paths below use `C:\xampp_jaka\`; adjust if XAMPP is installed elsewhere.

## Setup

### 1. Copy the project

Place the `hungry-potters` folder under XAMPP's `htdocs` directory. On this workstation, it is at:

```text
C:\xampp_jaka\htdocs\Clone\Hungry-Potters-\hungry-potters
```

Apache exposes the folder at `/hungry-potters` using an Alias in a configuration file outside the project. If the project is elsewhere, update the Alias target to its actual path.

### 2. Start XAMPP

Start **MySQL** from the XAMPP Control Panel before creating or importing the database. Start Apache there as well; you will restart it after configuring the Apache settings.

### 3. Set up the database

Follow **Database setup and upgrades** below to choose the correct path for a new database or an older existing database. MySQL must be running before you run database commands.

The application account needs only `SELECT`, `INSERT`, `UPDATE`, and `DELETE` permissions on this database. Create it using a private password:

```sql
CREATE DATABASE hungry_potters CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hungry_potters_app'@'localhost' IDENTIFIED BY 'replace-with-a-private-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON hungry_potters.* TO 'hungry_potters_app'@'localhost';
FLUSH PRIVILEGES;
```

Use the same private password in Apache's `HP_DB_PASSWORD` setting below. Do not put a real database password in this project or commit one.

### 4. Configure Apache database variables

The app reads `HP_DB_*` values through PHP's `getenv()`. Configure them in a file outside the project, for example:

```text
C:\xampp_jaka\apache\conf\extra\httpd-hungry-potters.conf
```

Adjust paths if XAMPP or the project is installed elsewhere. Replace the password placeholder with the private password created for the application account.

```apache
Alias "/hungry-potters" "C:/xampp_jaka/htdocs/Clone/Hungry-Potters-/hungry-potters"

<Directory "C:/xampp_jaka/htdocs/Clone/Hungry-Potters-/hungry-potters">
    Require all granted
    SetEnv HP_DB_HOST "localhost"
    SetEnv HP_DB_NAME "hungry_potters"
    SetEnv HP_DB_USER "hungry_potters_app"
    SetEnv HP_DB_PASSWORD "replace-with-a-private-password"
    SetEnv HP_DB_PORT "3306"
</Directory>

<Directory "C:/xampp_jaka/htdocs/Clone/Hungry-Potters-/hungry-potters/database">
    Require all denied
</Directory>

<Directory "C:/xampp_jaka/htdocs/Clone/Hungry-Potters-/hungry-potters/config">
    Require all denied
</Directory>
```

In `C:\xampp_jaka\apache\conf\httpd.conf`, add this line with the other `Include` directives:

```apache
Include conf/extra/httpd-hungry-potters.conf
```

Restart Apache after saving the configuration. Check its syntax in PowerShell:

```powershell
C:\xampp_jaka\apache\bin\httpd.exe -t
```

No PHP built-in server or per-session PowerShell environment setup is needed.

### 5. Open the app

Open `http://localhost/hungry-potters/login.php`.

#### Customer-facing queue display

Open `http://localhost/hungry-potters/queue-display.php` on the counter TV or tablet. For another device, use this PC's LAN IP instead of `localhost`, if the network and firewall allow it. Use browser fullscreen or kiosk mode. The display needs no login, refreshes every five seconds, and shows queue numbers only. Ready orders disappear 15 minutes after kitchen staff mark them completed.

#### Kitchen new-order alerts

Open `http://localhost/hungry-potters/kitchen.php`. New placed orders are highlighted and announced in a banner; the browser tab title shows the placed-order count. Sound starts off. Click **Sound alerts: off** to enable sound and hear a preview. The on/off choice is remembered, but click the button once after every reload to unlock browser audio again.

#### Default seed accounts

| Username | Role | Notes |
|---|---|---|
| `admin` | Admin | Must change password on first login |
| `cashier` | Cashier | Must change password on first login |
| `kitchen` | Kitchen | Must change password on first login |

All seed accounts require a new password on first login before accessing the dashboard.

## Database setup and upgrades

### New database

Create the database and application account as described in Setup, then choose one of these options:

- Import `database/database.sql` for a fresh installation.
- Import a database backup made with `mysqldump`.

A backup already contains its schema. `database/database.sql` already includes the current schema and the outcomes of the migrations. **Do not run the migration scripts on a new database or on a backup that already has the current schema.**

The SQL files target a database named `hungry_potters`. If `HP_DB_NAME` differs, select the configured database when running scripts and replace any `USE hungry_potters` statement with that database name.

### Older existing database

Back up the database first. Inspect its schema and apply only the missing upgrades, in order. These scripts are not a general-purpose rerunnable migration system: several use `ALTER TABLE ... ADD COLUMN` or `DROP INDEX` and will fail if run twice. If a migration is partly present, stop and reconcile the schema rather than rerunning it blindly.

1. **`database/migrations/20261031_management_takeout.sql`** — Adds menu availability, takeout slip number, cashier name, and the takeout slip counter table. **Not safe to run twice:** the added columns and unique constraint will fail if they already exist. Run only when these features are absent. It requires the earlier base tables (`menu_items` and `orders`); it does not depend on another listed upgrade.
2. **`database/order_cancellation.sql`** — Adds cancellation fields and the cancellation user foreign key to orders; extends the inventory movement enum for restores and waste. **Not safe to run twice:** the added columns and constraint will fail if already present. It requires the base `orders`, `users`, and `inventory_movements` tables. Its position relative to the other upgrades is **unverified**; the SQL shows no dependency on the other listed migration files.
3. **`database/migrations/20261117_daily_queue_numbers.sql`** — Replaces the global queue number index with a per-business-date unique key, adds the generated business date and queue counter table, and seeds counters from existing orders. **Not safe to run twice:** it drops an index and adds a column and key. It requires the older `orders` schema to have the `queue_number` index it drops and the needed order fields. It does not depend on the listed user, cancellation, or payment upgrades.
4. **`database/migrations/20261117_payment_tracking.sql`** — Creates payment history and cashier shift tables. **Safe to run twice at the table-creation level** because both use `CREATE TABLE IF NOT EXISTS`; it does not reconcile a partially or differently defined existing table, so verify the schema if either table already exists. It requires the base `orders` and `users` tables; it does not depend on the listed upgrades.
5. **`database/migrations/20261117_require_seed_password_reset.sql`** — Adds `must_change_password` and marks accounts with the known seed password hash as requiring a password change. **Not safe to run twice:** adding the column fails on a second run. It requires the base `users` table.
6. **`database/migrations/20261117_user_session_version.sql`** — Adds `session_version`. **Not safe to run twice:** adding the column fails on a second run. It depends on `20261117_require_seed_password_reset.sql` because it adds the column after `must_change_password`.

The numbered sequence is a practical upgrade order, but the SQL shows few dependencies between these scripts: the session-version migration must follow the password-reset migration; the other upgrades require their base tables and applicable older schema. The position of `order_cancellation.sql` in the sequence is **unverified**, as noted above. Check the live schema and apply only missing changes.

### Other SQL scripts

These scripts are not required for a new database created from `database/database.sql`:

- **`database/telegram_notifications.sql`** — Creates the legacy `notification_log` table. **Safe to run twice at the table-creation level** because it uses `CREATE TABLE IF NOT EXISTS`. The current app no longer has the Telegram notification integration; this table is optional legacy support.
- **`database/update_menu.sql`** — Updates menu categories, names, prices, descriptions, availability, and active status to the menu data in the script. It creates missing listed items and deactivates items not on that list. **Can be rerun technically**, but each run reapplies those menu values and can overwrite later menu edits or deactivate newer items. Use only when you intend to restore that menu data. Run before `update_recipes.sql`.
- **`database/update_recipes.sql`** — Adds missing inventory ingredients and fills recipes only for menu items that currently have no recipe. It leaves existing recipes unchanged. **Designed to be rerunnable**; it skips ingredients that already exist and dishes that already have recipes. Review the script's recipe assumptions before applying it to operational inventory/menu data.

## Moving to a new laptop

On the old laptop, export the database using its XAMPP MariaDB:

```powershell
cmd /c "C:\xampp_jaka\mysql\bin\mysqldump.exe -u root hungry_potters > hungry_potters_backup.sql"
```

Copy `hungry_potters_backup.sql` and the project folder to the new laptop. Follow **Setup** there, create the database and application account, then import the backup:

```powershell
cmd /c "C:\xampp_jaka\mysql\bin\mysql.exe -u root hungry_potters < hungry_potters_backup.sql"
```

The backup contains the database schema and data. Do not run the migrations on that imported database if it already has the current schema. Adjust the XAMPP paths in these commands if XAMPP is installed elsewhere.

## Backup scheduling

- Schedule the backup task under your own Windows user account, not under SYSTEM. The backup directory (`%USERPROFILE%\HungryPottersBackups`) may not be writable by SYSTEM in all configurations.
- Regularly copy the backups folder to another drive or cloud storage. Backups stored only on the local XAMPP machine are at risk if the system or drive fails.
- Do not embed database passwords in the backup script or README. `scripts/backup-db.bat` uses the XAMPP root user without a password, which is suitable only for local XAMPP setups configured that way.

## Health check

From PowerShell, run the health check and pass the project path:

```powershell
.\scripts\check-health.ps1 -ProjectPath "C:\xampp_jaka\htdocs\Clone\Hungry-Potters-\hungry-potters"
```

Adjust the path if XAMPP or the project is installed elsewhere.

## Troubleshooting

### MySQL/MariaDB will not start

Start MySQL from the XAMPP Control Panel and enable its autostart there if desired. The older `MySQL80` Windows service can conflict with XAMPP's MariaDB on port 3306. If you do not need that service, set it to **Disabled** in `services.msc`.

### HTTP 500 on the login page

This usually means PHP cannot connect to MySQL. Check that:

1. MySQL is running in XAMPP.
2. The `HP_DB_*` values are set in the external Apache config and Apache was restarted.
3. The `hungry_potters_app` database user exists and has the correct password and grants.

### Port 3306 already in use

Another MySQL instance may be running. Check whether the old `MySQL80` service is using the port; if it is not needed, set it to **Disabled** in `services.msc`, then start MySQL from the XAMPP Control Panel.
