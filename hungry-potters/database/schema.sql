CREATE DATABASE IF NOT EXISTS hungry_potters
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hungry_potters;

-- For existing installations, follow the ordered upgrade steps in README.md.
-- Before deploying the matching application code, also apply
-- database/migrations/20261117_require_seed_password_reset.sql, then
-- database/migrations/20261117_user_session_version.sql, then
-- database/migrations/20261122_login_rate_limiting.sql to older installations.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'cashier', 'kitchen') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    session_version INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_failures (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARBINARY(16) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_login_failures_username_ip_time (username, ip_address, attempted_at),
    INDEX idx_login_failures_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    category_type ENUM('menu', 'inventory') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_name_type (name, category_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS menu_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL DEFAULT '',
    price DECIMAL(10, 2) NOT NULL,
    image_url VARCHAR(500) DEFAULT NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_menu_items_category
        FOREIGN KEY (category_id) REFERENCES categories(id),
    INDEX idx_menu_items_category_active (category_id, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NOT NULL DEFAULT '',
    unit VARCHAR(30) NOT NULL,
    current_stock DECIMAL(10, 2) NOT NULL DEFAULT 0,
    low_stock_level DECIMAL(10, 2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_items_category
        FOREIGN KEY (category_id) REFERENCES categories(id),
    INDEX idx_inventory_items_stock (current_stock, low_stock_level, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS menu_recipes (
    menu_item_id INT UNSIGNED NOT NULL,
    inventory_item_id INT UNSIGNED NOT NULL,
    quantity_required DECIMAL(10, 2) NOT NULL,
    PRIMARY KEY (menu_item_id, inventory_item_id),
    CONSTRAINT fk_menu_recipes_menu_item
        FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_menu_recipes_inventory_item
        FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(32) NOT NULL UNIQUE,
    checkout_token CHAR(36) CHARACTER SET ascii DEFAULT NULL,
    checkout_request_hash CHAR(64) CHARACTER SET ascii DEFAULT NULL,
    checkout_mode ENUM('prepaid','cod') DEFAULT NULL,
    UNIQUE KEY uq_orders_checkout_token (checkout_token),
    slip_number VARCHAR(32) DEFAULT NULL UNIQUE,
    queue_number INT UNSIGNED NOT NULL,
    order_type ENUM('dine_in', 'takeout', 'delivery') NOT NULL,
    location VARCHAR(100) DEFAULT NULL,
    status ENUM('placed', 'preparing', 'completed', 'cancelled')
        NOT NULL DEFAULT 'placed',
    subtotal DECIMAL(10, 2) NOT NULL,
    total DECIMAL(10, 2) NOT NULL,
    kitchen_note VARCHAR(500) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    cashier_name VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    cancelled_by INT UNSIGNED DEFAULT NULL,
    cancel_reason VARCHAR(255) DEFAULT NULL,
    inventory_restored TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    business_date DATE GENERATED ALWAYS AS (DATE(created_at)) STORED,
    CONSTRAINT fk_orders_user
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_orders_cancelled_by
        FOREIGN KEY (cancelled_by) REFERENCES users(id)
        ON DELETE SET NULL,
    UNIQUE KEY uq_orders_business_date_queue_number (business_date, queue_number),
    INDEX idx_orders_status_created (status, created_at),
    INDEX idx_orders_created_at (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_slip_counters (
    slip_date DATE NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_queue_counters (
    business_date DATE NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    menu_item_id INT UNSIGNED DEFAULT NULL,
    item_name VARCHAR(100) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    line_total DECIMAL(10, 2) NOT NULL,
    item_note VARCHAR(255) DEFAULT NULL,
    status ENUM('placed', 'preparing', 'done') NOT NULL DEFAULT 'placed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_order_items_menu_item
        FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
        ON DELETE SET NULL,
    INDEX idx_order_items_order_status (order_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED DEFAULT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    movement_type ENUM(
        'order_deduction',
        'restock',
        'manual_adjustment',
        'cancellation_restore',
        'waste'
    ) NOT NULL,
    quantity_change DECIMAL(10, 2) NOT NULL,
    quantity_before DECIMAL(10, 2) NOT NULL,
    quantity_after DECIMAL(10, 2) NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_movements_item
        FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
    CONSTRAINT fk_inventory_movements_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_inventory_movements_user
        FOREIGN KEY (changed_by) REFERENCES users(id)
        ON DELETE SET NULL,
    INDEX idx_inventory_movements_created (created_at),
    INDEX idx_inventory_movements_order (order_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    old_status ENUM('placed', 'preparing', 'completed', 'cancelled') DEFAULT NULL,
    new_status ENUM('placed', 'preparing', 'completed', 'cancelled') NOT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(255) DEFAULT NULL,
    CONSTRAINT fk_order_status_history_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_order_status_history_user
        FOREIGN KEY (changed_by) REFERENCES users(id)
        ON DELETE SET NULL,
    INDEX idx_order_status_history_order (order_id, changed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    entry_type ENUM('payment', 'refund', 'void') NOT NULL,
    related_payment_id BIGINT UNSIGNED DEFAULT NULL,
    method ENUM('cash', 'ewallet') NOT NULL,
    amount DECIMAL(10, 2) UNSIGNED NOT NULL,
    tendered_amount DECIMAL(10, 2) UNSIGNED DEFAULT NULL,
    change_amount DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0,
    reference_code VARCHAR(100) DEFAULT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    business_date DATE GENERATED ALWAYS AS (DATE(recorded_at)) STORED,
    CONSTRAINT fk_order_payments_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_order_payments_related
        FOREIGN KEY (related_payment_id, order_id)
        REFERENCES order_payments(id, order_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_order_payments_user
        FOREIGN KEY (recorded_by) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT chk_order_payments_amount CHECK (amount > 0),
    CONSTRAINT chk_order_payments_cash CHECK (
        (entry_type = 'payment' AND method = 'cash'
            AND tendered_amount IS NOT NULL
            AND tendered_amount >= amount
            AND change_amount = tendered_amount - amount)
        OR (entry_type = 'payment' AND method = 'ewallet'
            AND tendered_amount IS NULL AND change_amount = 0)
        OR (entry_type IN ('refund', 'void')
            AND related_payment_id IS NOT NULL
            AND tendered_amount IS NULL AND change_amount = 0)
    ),
    CONSTRAINT chk_order_payments_parent CHECK (
        (entry_type = 'payment' AND related_payment_id IS NULL)
        OR (entry_type IN ('refund', 'void') AND related_payment_id IS NOT NULL)
    ),
    CONSTRAINT chk_order_payments_reason CHECK (
        entry_type = 'payment' OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0)
    ),
    UNIQUE KEY uq_order_payments_id_order (id, order_id),
    INDEX idx_order_payments_order_date (order_id, recorded_at),
    INDEX idx_order_payments_cashier_date (recorded_by, business_date),
    INDEX idx_order_payments_related (related_payment_id, order_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cashier_shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_date DATE NOT NULL,
    cashier_id INT UNSIGNED NOT NULL,
    opening_float DECIMAL(10, 2) UNSIGNED NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opened_by INT UNSIGNED DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    cash_expected DECIMAL(10, 2) DEFAULT NULL,
    cash_counted DECIMAL(10, 2) DEFAULT NULL,
    cash_variance DECIMAL(10, 2) DEFAULT NULL,
    closed_by INT UNSIGNED DEFAULT NULL,
    CONSTRAINT fk_cashier_shifts_cashier
        FOREIGN KEY (cashier_id) REFERENCES users(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_cashier_shifts_opened_by
        FOREIGN KEY (opened_by) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_cashier_shifts_closed_by
        FOREIGN KEY (closed_by) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT uq_cashier_shifts_date_cashier UNIQUE (business_date, cashier_id),
    CONSTRAINT chk_cashier_shifts_closeout CHECK (
        (closed_at IS NULL AND cash_expected IS NULL
            AND cash_counted IS NULL AND cash_variance IS NULL
            AND closed_by IS NULL)
        OR (closed_at IS NOT NULL AND cash_expected IS NOT NULL
            AND cash_counted IS NOT NULL AND cash_variance IS NOT NULL
            AND closed_by IS NOT NULL)
    ),
    INDEX idx_cashier_shifts_cashier_date (cashier_id, business_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type VARCHAR(32) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('queued', 'sent', 'failed') NOT NULL DEFAULT 'queued',
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX idx_notification_log_queue (status, created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

