ALTER TABLE users
    ADD COLUMN failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER password_hash,
    ADD COLUMN locked_until DATETIME NULL AFTER failed_login_attempts;
