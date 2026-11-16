USE hungry_potters;

ALTER TABLE orders
    ADD COLUMN cancelled_by INT(10) UNSIGNED NULL AFTER cancelled_at,
    ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_by,
    ADD COLUMN inventory_restored TINYINT(1) NOT NULL DEFAULT 0 AFTER cancel_reason,
    ADD CONSTRAINT fk_orders_cancelled_by
        FOREIGN KEY (cancelled_by) REFERENCES users (id)
        ON DELETE SET NULL;

ALTER TABLE inventory_movements
    MODIFY COLUMN movement_type
        ENUM(
            'order_deduction',
            'restock',
            'manual_adjustment',
            'cancellation_restore',
            'waste'
        ) NOT NULL;
