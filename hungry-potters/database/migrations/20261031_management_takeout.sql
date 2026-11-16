USE hungry_potters;

ALTER TABLE menu_items
    ADD COLUMN is_available TINYINT(1) NOT NULL DEFAULT 1 AFTER image_url;

ALTER TABLE orders
    ADD COLUMN slip_number VARCHAR(32) DEFAULT NULL UNIQUE AFTER order_number,
    ADD COLUMN cashier_name VARCHAR(100) DEFAULT NULL AFTER created_by;

CREATE TABLE IF NOT EXISTS order_slip_counters (
    slip_date DATE NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;
