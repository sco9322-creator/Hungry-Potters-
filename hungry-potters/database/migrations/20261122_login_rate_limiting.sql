-- For upgrading an older existing database only; apply before deploying the
-- matching application code. New databases already get this table from database.sql.
CREATE TABLE IF NOT EXISTS login_failures (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARBINARY(16) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_login_failures_username_ip_time (username, ip_address, attempted_at),
    INDEX idx_login_failures_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;
