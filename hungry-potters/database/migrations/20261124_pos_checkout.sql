-- For upgrading an older database only. Historical orders keep NULL checkout metadata.
ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS checkout_token CHAR(36) CHARACTER SET ascii DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS checkout_request_hash CHAR(64) CHARACTER SET ascii DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS checkout_mode ENUM('prepaid','cod') DEFAULT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_orders_checkout_token (checkout_token);
