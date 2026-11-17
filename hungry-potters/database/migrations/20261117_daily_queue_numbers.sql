-- Apply once to an existing installation before deploying the matching application code.
ALTER TABLE orders
    DROP INDEX queue_number,
    MODIFY COLUMN order_number VARCHAR(32) NOT NULL,
    ADD COLUMN business_date DATE GENERATED ALWAYS AS (DATE(created_at)) STORED AFTER created_at,
    ADD UNIQUE KEY uq_orders_business_date_queue_number (business_date, queue_number);

CREATE TABLE IF NOT EXISTS order_queue_counters (
    business_date DATE NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO order_queue_counters (business_date, last_number)
SELECT business_date, MAX(queue_number)
FROM orders
GROUP BY business_date
ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number));
