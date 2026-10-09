-- My Borrowings pickup support. Upgrade existing installations with migrate_my_borrowings.php.
-- Additive only: no row/status conversion, stock recalculation, deletion, or new tables.
-- Append borrowed to preserve the ordinal positions of all existing enum values.
-- @when enum borrowing_requests borrowed
ALTER TABLE borrowing_requests MODIFY COLUMN status ENUM('pending','approved','rejected','returned','cancelled','borrowed') NOT NULL DEFAULT 'pending';
-- @when column borrowing_requests picked_up_at
ALTER TABLE borrowing_requests ADD COLUMN picked_up_at DATETIME NULL DEFAULT NULL;
-- @when column borrowing_requests picked_up_by_staff_id
ALTER TABLE borrowing_requests ADD COLUMN picked_up_by_staff_id INT NULL DEFAULT NULL;
-- @when index borrowing_requests idx_requests_pickup_staff
ALTER TABLE borrowing_requests ADD INDEX idx_requests_pickup_staff (picked_up_by_staff_id);
-- @when constraint borrowing_requests fk_request_pickup_staff
ALTER TABLE borrowing_requests ADD CONSTRAINT fk_request_pickup_staff FOREIGN KEY (picked_up_by_staff_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
-- @when constraint borrowing_requests chk_request_pickup_evidence
ALTER TABLE borrowing_requests ADD CONSTRAINT chk_request_pickup_evidence CHECK ((picked_up_at IS NULL AND picked_up_by_staff_id IS NULL AND status <> 'borrowed') OR (picked_up_at IS NOT NULL AND picked_up_by_staff_id IS NOT NULL AND status IN ('borrowed','returned')));
-- @when constraint borrowing_requests chk_request_pickup_dates
ALTER TABLE borrowing_requests ADD CONSTRAINT chk_request_pickup_dates CHECK (picked_up_at IS NULL OR (borrow_date IS NOT NULL AND expected_return_date IS NOT NULL AND DATE(picked_up_at) >= borrow_date AND DATE(picked_up_at) <= expected_return_date));
-- The existing (user_id, request_date) index includes the InnoDB primary key request_id.
-- It already supports date + ID sorting, so no duplicate all-history index is added.
-- @when index borrowing_requests idx_requests_user_status_date
ALTER TABLE borrowing_requests ADD INDEX idx_requests_user_status_date (user_id,status,request_date);
