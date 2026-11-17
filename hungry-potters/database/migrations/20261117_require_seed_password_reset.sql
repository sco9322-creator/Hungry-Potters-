-- Apply once to an existing installation before deploying the matching application code.
ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

UPDATE users
SET must_change_password = 1
WHERE password_hash = '$2y$10$GcIr8FqWdQvHiD/s.RIyIu9CMU3jrcF.khL131SBrQdP3pC2/ByZS';
