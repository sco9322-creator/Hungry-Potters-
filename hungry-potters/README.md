# Hungry Potters Operations

## Database configuration

The application reads database settings from the PHP runtime environment. Set all four variables before starting the web server:

- `HP_DB_HOST`
- `HP_DB_NAME`
- `HP_DB_USER`
- `HP_DB_PASSWORD`

`HP_DB_PORT` is optional and defaults to `3306`.

For local PowerShell testing, set them in the same terminal that starts PHP:

```powershell
$env:HP_DB_HOST = 'localhost'
$env:HP_DB_NAME = 'hungry_potters'
$env:HP_DB_USER = '<application-user>'
$env:HP_DB_PASSWORD = '<strong-unique-password>'
php -S 127.0.0.1:8000 -t .
```

For XAMPP Apache or another deployment, provide the variables through the server's environment and restart the server after changing them. Do not put database credentials in tracked files or use MariaDB's `root` account for the application.

Create a dedicated database account with access limited to this application's database. For example, run the following as a database administrator after replacing the password placeholder:

```sql
CREATE USER 'hungry_potters_app'@'localhost' IDENTIFIED BY '<strong-unique-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON hungry_potters.* TO 'hungry_potters_app'@'localhost';
```

Database creation and migrations should be performed by an administrator account, not by the application's runtime account.

## Database installation and upgrades

For a fresh installation, run `database/database.sql` once. It includes the current user-session, daily queue, order-cancellation, and notification schemas; do not then run the upgrade scripts below.

For an existing installation, back up the database first and apply only the missing upgrades, in this order, before deploying the matching application code:

1. If the installation predates management/takeout support, run `database/migrations/20261031_management_takeout.sql`. Skip it if the takeout columns and `order_slip_counters` already exist; it is not safe to rerun. If only some of these objects exist, stop and reconcile the partial upgrade before continuing.
2. Run `database/migrations/20261117_require_seed_password_reset.sql`.
3. Run `database/migrations/20261117_user_session_version.sql`.
4. On the baseline schema without cancellation support, run `database/order_cancellation.sql` once. Skip it if its three cancellation columns and movement types are already present; if only part of the migration is present, do not rerun it blindly.
5. Run `database/telegram_notifications.sql` to create the notification queue table; it is safe to rerun.
6. Run `database/migrations/20261117_daily_queue_numbers.sql` once to reset queue numbers by business date while retaining globally unique order numbers.
7. Run `database/migrations/20261117_payment_tracking.sql` to add payment history and cashier shift closeout records.

Queue business dates follow the MariaDB server's local date; configure its time zone to the restaurant's business time zone. The SQL scripts target a database named `hungry_potters`. If `HP_DB_NAME` is different, select that database when running the scripts and replace their `USE hungry_potters` statement with the configured database name.

Payments are recorded manually after collection; the application does not charge cards or e-wallets. Supported methods are cash and GCash/e-wallet, and an order may have split payments. Open a cashier shift with its starting cash float before recording payments; each cashier may open and close one shift per business date. Refunds and voids are recorded as separate audit entries. Orders with payment history cannot be edited, and cancellation is blocked until their net paid balance is refunded or voided. Existing or seeded example orders are not marked paid by assumption; record historical payments only when they can be verified.
