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
