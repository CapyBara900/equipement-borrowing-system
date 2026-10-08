-- Existing requests keep their agreed return dates. New requests use this limit.
ALTER TABLE equipment
    ADD COLUMN borrowing_time_limit_days INT UNSIGNED NOT NULL DEFAULT 7 AFTER available_quantity,
    ADD CONSTRAINT chk_equipment_borrowing_limit CHECK (borrowing_time_limit_days BETWEEN 1 AND 3650);
