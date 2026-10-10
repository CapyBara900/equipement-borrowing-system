-- Upgrade existing databases; never rerun schema.sql on existing data.
-- Inspect duplicates first. Resolve any returned names before ALTER TABLE.
SELECT name COLLATE utf8mb4_unicode_ci AS conflicting_name,
       GROUP_CONCAT(user_id ORDER BY user_id) AS user_ids, COUNT(*) AS accounts
FROM users GROUP BY name COLLATE utf8mb4_unicode_ci HAVING COUNT(*) > 1;
SELECT email COLLATE utf8mb4_unicode_ci AS conflicting_email,
       GROUP_CONCAT(user_id ORDER BY user_id) AS user_ids, COUNT(*) AS accounts
FROM users GROUP BY email COLLATE utf8mb4_unicode_ci HAVING COUNT(*) > 1;

-- The CLI runner performs these prechecks and supports --dry-run / safe reruns.
-- email already has a UNIQUE constraint in the existing application schema.
ALTER TABLE users
    MODIFY name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY password_hash VARCHAR(255) NOT NULL,
    ADD UNIQUE KEY uq_users_profile_name (name);

-- Parameterized operations used by the PHP endpoint (bind values with PDO).
-- UPDATE users SET name = :name, email = :email WHERE user_id = :user_id;
-- UPDATE users SET password_hash = :password_hash WHERE user_id = :user_id;
