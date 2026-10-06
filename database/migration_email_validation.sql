-- Expand the email column to the RFC maximum and make its case-insensitive
-- uniqueness explicit for databases created from an older schema.
ALTER TABLE users
    MODIFY email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
