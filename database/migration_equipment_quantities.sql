-- Run this migration on an existing database before using quantity-aware APIs.
ALTER TABLE equipment
    ADD COLUMN total_quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER category_id,
    ADD COLUMN available_quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER total_quantity;

ALTER TABLE borrowing_requests
    ADD COLUMN requested_quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER expected_return_date;

ALTER TABLE returns
    ADD COLUMN returned_quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER actual_return_date;

UPDATE equipment
SET available_quantity = LEAST(
    total_quantity,
    GREATEST(
        0,
        total_quantity - COALESCE((
            SELECT SUM(r.requested_quantity)
            FROM borrowing_requests r
            WHERE r.equipment_id = equipment.equipment_id
              AND r.status IN ('pending', 'approved', 'borrowed')
        ), 0)
    )
);

ALTER TABLE equipment
    ADD CONSTRAINT chk_equipment_quantities
        CHECK (available_quantity <= total_quantity);
