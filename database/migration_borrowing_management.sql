-- Additive Borrowing Management migration. Use the CLI runner, not direct SQL import.
-- The runner validates existing objects, backs up the database, and skips completed steps.
-- No records, stock values, dates or existing enum positions are rewritten.

-- @when index borrowing_checkouts uq_checkouts_owner
ALTER TABLE borrowing_checkouts ADD UNIQUE KEY uq_checkouts_owner (checkout_id,user_id);

-- @when index borrowing_requests idx_requests_checkout_owner
ALTER TABLE borrowing_requests ADD INDEX idx_requests_checkout_owner (checkout_id,user_id);

-- @when foreign borrowing_requests fk_request_user_preserve
ALTER TABLE borrowing_requests DROP FOREIGN KEY fk_request_user, ADD CONSTRAINT fk_request_user_preserve FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- @when foreign borrowing_requests fk_request_equipment_preserve
ALTER TABLE borrowing_requests DROP FOREIGN KEY fk_request_equipment, ADD CONSTRAINT fk_request_equipment_preserve FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- @when foreign borrowing_checkouts fk_checkout_user_preserve
ALTER TABLE borrowing_checkouts DROP FOREIGN KEY fk_checkout_user, ADD CONSTRAINT fk_checkout_user_preserve FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- @when foreign borrowing_requests fk_request_checkout_preserve
ALTER TABLE borrowing_requests DROP FOREIGN KEY fk_request_checkout, ADD CONSTRAINT fk_request_checkout_preserve FOREIGN KEY (checkout_id) REFERENCES borrowing_checkouts(checkout_id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- @when foreign borrowing_requests fk_request_checkout_owner
ALTER TABLE borrowing_requests ADD CONSTRAINT fk_request_checkout_owner FOREIGN KEY (checkout_id,user_id) REFERENCES borrowing_checkouts(checkout_id,user_id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- @when foreign returns fk_return_request_preserve
ALTER TABLE returns DROP FOREIGN KEY fk_return_request, ADD CONSTRAINT fk_return_request_preserve FOREIGN KEY (request_id) REFERENCES borrowing_requests(request_id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- @when foreign returns fk_return_staff_preserve
ALTER TABLE returns DROP FOREIGN KEY fk_return_staff, ADD CONSTRAINT fk_return_staff_preserve FOREIGN KEY (processed_by_staff_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- @when foreign equipment_condition_reports fk_condition_equipment_preserve
ALTER TABLE equipment_condition_reports DROP FOREIGN KEY fk_condition_equipment, ADD CONSTRAINT fk_condition_equipment_preserve FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- @when foreign equipment_condition_reports fk_condition_reporter_preserve
ALTER TABLE equipment_condition_reports DROP FOREIGN KEY fk_condition_reporter, ADD CONSTRAINT fk_condition_reporter_preserve FOREIGN KEY (reported_by_user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- @when index borrowing_requests idx_requests_status_date
ALTER TABLE borrowing_requests ADD INDEX idx_requests_status_date (status,request_date,request_id);

-- @when check borrowing_requests chk_request_dates
ALTER TABLE borrowing_requests ADD CONSTRAINT chk_request_dates CHECK (borrow_date IS NULL OR expected_return_date IS NULL OR expected_return_date >= borrow_date);

-- @when check equipment chk_equipment_stock_bounds
ALTER TABLE equipment ADD CONSTRAINT chk_equipment_stock_bounds CHECK (total_quantity >= 1 AND available_quantity >= 0 AND available_quantity <= total_quantity);

-- @when check borrowing_requests chk_request_quantity_positive
ALTER TABLE borrowing_requests ADD CONSTRAINT chk_request_quantity_positive CHECK (requested_quantity >= 1);

-- @when check returns chk_return_quantity_positive
ALTER TABLE returns ADD CONSTRAINT chk_return_quantity_positive CHECK (returned_quantity >= 1);

-- Create last: existing admin APIs require this complete storage before allowing actions.
-- @when table borrowing_status_history borrowing_status_history
CREATE TABLE borrowing_status_history (
    history_id INT NOT NULL AUTO_INCREMENT,
    request_id INT NOT NULL,
    from_status ENUM('pending','approved','borrowed','returned','rejected','cancelled') NOT NULL,
    to_status ENUM('pending','approved','borrowed','returned','rejected','cancelled') NOT NULL,
    changed_by_user_id INT NOT NULL,
    changed_at DATETIME NOT NULL,
    PRIMARY KEY (history_id),
    UNIQUE KEY uq_borrowing_history_request_status (request_id,to_status),
    INDEX idx_borrowing_history_request_time (request_id,changed_at,history_id),
    INDEX idx_borrowing_history_actor_time (changed_by_user_id,changed_at),
    CONSTRAINT fk_borrowing_history_request FOREIGN KEY (request_id) REFERENCES borrowing_requests(request_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_borrowing_history_actor FOREIGN KEY (changed_by_user_id) REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_borrowing_history_transition CHECK (
        (from_status='pending' AND to_status IN ('approved','rejected','cancelled'))
        OR (from_status='approved' AND to_status='borrowed')
        OR (from_status='borrowed' AND to_status='returned')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
