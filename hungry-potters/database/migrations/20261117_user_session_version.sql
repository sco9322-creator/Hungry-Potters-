-- Apply after 20261117_require_seed_password_reset.sql and before deploying the matching application code.
ALTER TABLE users
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER must_change_password;
