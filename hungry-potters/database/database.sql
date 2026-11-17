CREATE DATABASE IF NOT EXISTS hungry_potters
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hungry_potters;

-- For existing installations, follow the ordered upgrade steps in README.md.
-- Before deploying the matching application code, also apply
-- database/migrations/20261117_require_seed_password_reset.sql, then
-- database/migrations/20261117_user_session_version.sql to existing installations.

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

-- Seeded accounts are required to choose a unique password at first sign-in.
INSERT IGNORE INTO users
    (id, username, full_name, password_hash, role, must_change_password)
VALUES
    (1, 'admin', 'Hungry Potters Admin', '$2y$10$GcIr8FqWdQvHiD/s.RIyIu9CMU3jrcF.khL131SBrQdP3pC2/ByZS', 'admin', 1),
    (2, 'cashier', 'Sarah M.', '$2y$10$GcIr8FqWdQvHiD/s.RIyIu9CMU3jrcF.khL131SBrQdP3pC2/ByZS', 'cashier', 1),
    (3, 'kitchen', 'Kitchen Staff', '$2y$10$GcIr8FqWdQvHiD/s.RIyIu9CMU3jrcF.khL131SBrQdP3pC2/ByZS', 'kitchen', 1);

INSERT IGNORE INTO categories (id, name, category_type) VALUES
    (1, 'Silog Meals', 'menu'),
    (2, 'Drinks', 'menu'),
    (3, 'Proteins', 'inventory'),
    (4, 'Produce', 'inventory'),
    (5, 'Pantry', 'inventory'),
    (6, 'Beverages', 'inventory');

INSERT IGNORE INTO menu_items (id, category_id, name, description, price) VALUES
    (1, 1, 'Tapsilog', 'Garlic rice, egg, and marinated beef tapa', 125.00),
    (2, 1, 'Longsilog', 'Garlic rice, egg, and longganisa', 115.00),
    (3, 1, 'Tocilog', 'Garlic rice, egg, and sweet cured pork tocino', 110.00),
    (4, 1, 'Bangsilog', 'Garlic rice, egg, and crispy bangus', 130.00),
    (5, 1, 'Hotsilog', 'Garlic rice, egg, and red hotdogs', 105.00),
    (6, 2, 'House Iced Tea', 'Chilled brewed tea with calamansi', 35.00);

INSERT IGNORE INTO inventory_items
    (id, category_id, name, description, unit, current_stock, low_stock_level)
VALUES
    (1, 3, 'Tocino', 'Sweet cured pork', 'servings', 0, 6),
    (2, 3, 'Beef Tapa', 'Marinated sirloin', 'servings', 9, 10),
    (3, 3, 'Longganisa', 'Sweet pork sausage', 'pcs', 10, 8),
    (4, 5, 'Garlic Rice', 'Toasted sinangag', 'servings', 42, 15),
    (5, 4, 'Eggs', 'Farm fresh Grade A', 'pcs', 36, 12),
    (6, 3, 'Bangus', 'Marinated milkfish belly', 'pcs', 11, 4),
    (7, 3, 'Hotdog', 'Jumbo classic red', 'pcs', 30, 10),
    (8, 6, 'Iced Tea', 'House calamansi brew', 'glasses', 18, 8);

INSERT IGNORE INTO menu_recipes (menu_item_id, inventory_item_id, quantity_required) VALUES
    (1, 4, 1), (1, 5, 1), (1, 2, 1),
    (2, 4, 1), (2, 5, 1), (2, 3, 2),
    (3, 4, 1), (3, 5, 1), (3, 1, 1),
    (4, 4, 1), (4, 5, 1), (4, 6, 1),
    (5, 4, 1), (5, 5, 1), (5, 7, 2),
    (6, 8, 1);

-- Sample queue entries match the screens. Importing these examples does not
-- make historical inventory deductions; only new POS orders change stock.
INSERT IGNORE INTO orders
    (id, order_number, queue_number, order_type, location, status, subtotal, total,
     kitchen_note, created_by, created_at, started_at, completed_at, cancelled_at)
VALUES
    (1, 'ORD-0001', 1, 'takeout', NULL, 'completed', 125.00, 125.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 210 MINUTE), DATE_SUB(NOW(), INTERVAL 205 MINUTE), DATE_SUB(NOW(), INTERVAL 195 MINUTE), NULL),
    (2, 'ORD-0002', 2, 'dine_in', 'Table 03', 'completed', 150.00, 150.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 195 MINUTE), DATE_SUB(NOW(), INTERVAL 190 MINUTE), DATE_SUB(NOW(), INTERVAL 180 MINUTE), NULL),
    (3, 'ORD-0003', 3, 'takeout', 'Order Slip #71', 'completed', 130.00, 130.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 180 MINUTE), DATE_SUB(NOW(), INTERVAL 175 MINUTE), DATE_SUB(NOW(), INTERVAL 165 MINUTE), NULL),
    (4, 'ORD-0004', 4, 'dine_in', 'Table 01', 'completed', 210.00, 210.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 165 MINUTE), DATE_SUB(NOW(), INTERVAL 160 MINUTE), DATE_SUB(NOW(), INTERVAL 150 MINUTE), NULL),
    (5, 'ORD-0005', 5, 'takeout', NULL, 'completed', 160.00, 160.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 150 MINUTE), DATE_SUB(NOW(), INTERVAL 145 MINUTE), DATE_SUB(NOW(), INTERVAL 135 MINUTE), NULL),
    (6, 'ORD-0006', 6, 'dine_in', 'Table 05', 'completed', 115.00, 115.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 135 MINUTE), DATE_SUB(NOW(), INTERVAL 130 MINUTE), DATE_SUB(NOW(), INTERVAL 120 MINUTE), NULL),
    (7, 'ORD-0007', 7, 'takeout', 'Order Slip #79', 'completed', 165.00, 165.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 120 MINUTE), DATE_SUB(NOW(), INTERVAL 115 MINUTE), DATE_SUB(NOW(), INTERVAL 105 MINUTE), NULL),
    (8, 'ORD-0008', 8, 'dine_in', 'Table 08', 'completed', 235.00, 235.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 105 MINUTE), DATE_SUB(NOW(), INTERVAL 100 MINUTE), DATE_SUB(NOW(), INTERVAL 90 MINUTE), NULL),
    (9, 'ORD-0009', 9, 'takeout', NULL, 'completed', 480.00, 480.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 90 MINUTE), DATE_SUB(NOW(), INTERVAL 85 MINUTE), DATE_SUB(NOW(), INTERVAL 75 MINUTE), NULL),
    (10, 'ORD-0010', 10, 'takeout', 'Order Slip #84', 'cancelled', 110.00, 110.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 67 MINUTE), NULL, NULL, DATE_SUB(NOW(), INTERVAL 62 MINUTE)),
    (11, 'ORD-0011', 11, 'dine_in', 'Table 07', 'completed', 220.00, 220.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 55 MINUTE), DATE_SUB(NOW(), INTERVAL 50 MINUTE), DATE_SUB(NOW(), INTERVAL 40 MINUTE), NULL),
    (12, 'ORD-0012', 12, 'takeout', 'Order Slip #87', 'completed', 150.00, 150.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 25 MINUTE), DATE_SUB(NOW(), INTERVAL 20 MINUTE), DATE_SUB(NOW(), INTERVAL 11 MINUTE), NULL),
    (13, 'ORD-0013', 13, 'dine_in', 'Table 04', 'preparing', 450.00, 450.00, 'Well-done eggs', 2,
     DATE_SUB(NOW(), INTERVAL 14 MINUTE), DATE_SUB(NOW(), INTERVAL 12 MINUTE), NULL, NULL),
    (14, 'ORD-0014', 14, 'dine_in', 'Table 09', 'preparing', 105.00, 105.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 8 MINUTE), DATE_SUB(NOW(), INTERVAL 7 MINUTE), NULL, NULL),
    (15, 'ORD-0015', 15, 'dine_in', 'Table 02', 'placed', 240.00, 240.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 3 MINUTE), NULL, NULL, NULL),
    (16, 'ORD-0016', 16, 'takeout', 'Bag #12', 'placed', 295.00, 295.00, NULL, 2,
     DATE_SUB(NOW(), INTERVAL 1 MINUTE), NULL, NULL, NULL);

INSERT INTO order_queue_counters (business_date, last_number)
SELECT business_date, MAX(queue_number)
FROM orders
GROUP BY business_date
ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number));

INSERT IGNORE INTO order_items
    (id, order_id, menu_item_id, item_name, quantity, unit_price, line_total, status)
VALUES
    (1, 1, 1, 'Tapsilog', 1, 125.00, 125.00, 'done'),
    (2, 2, 2, 'Longsilog', 1, 115.00, 115.00, 'done'),
    (3, 2, 6, 'House Iced Tea', 1, 35.00, 35.00, 'done'),
    (4, 3, 4, 'Bangsilog', 1, 130.00, 130.00, 'done'),
    (5, 4, 5, 'Hotsilog', 2, 105.00, 210.00, 'done'),
    (6, 5, 1, 'Tapsilog', 1, 125.00, 125.00, 'done'),
    (7, 5, 6, 'House Iced Tea', 1, 35.00, 35.00, 'done'),
    (8, 6, 2, 'Longsilog', 1, 115.00, 115.00, 'done'),
    (9, 7, 4, 'Bangsilog', 1, 130.00, 130.00, 'done'),
    (10, 7, 6, 'House Iced Tea', 1, 35.00, 35.00, 'done'),
    (11, 8, 4, 'Bangsilog', 1, 130.00, 130.00, 'done'),
    (12, 8, 5, 'Hotsilog', 1, 105.00, 105.00, 'done'),
    (13, 9, 1, 'Tapsilog', 3, 125.00, 375.00, 'done'),
    (14, 9, 6, 'House Iced Tea', 3, 35.00, 105.00, 'done'),
    (15, 10, 3, 'Tocilog', 1, 110.00, 110.00, 'placed'),
    (16, 11, 3, 'Tocilog', 2, 110.00, 220.00, 'done'),
    (17, 12, 2, 'Longsilog', 1, 115.00, 115.00, 'done'),
    (18, 12, 6, 'House Iced Tea', 1, 35.00, 35.00, 'done'),
    (19, 13, 1, 'Tapsilog', 2, 125.00, 250.00, 'preparing'),
    (20, 13, 4, 'Bangsilog', 1, 130.00, 130.00, 'preparing'),
    (21, 13, 6, 'House Iced Tea', 2, 35.00, 70.00, 'preparing'),
    (22, 14, 5, 'Hotsilog', 1, 105.00, 105.00, 'preparing'),
    (23, 15, 2, 'Longsilog', 1, 115.00, 115.00, 'placed'),
    (24, 15, 1, 'Tapsilog', 1, 125.00, 125.00, 'placed'),
    (25, 16, 4, 'Bangsilog', 2, 130.00, 260.00, 'placed'),
    (26, 16, 6, 'House Iced Tea', 1, 35.00, 35.00, 'placed');
