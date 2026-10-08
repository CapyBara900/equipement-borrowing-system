-- Borrowing cart Part 3. Additive only; never resets or recalculates existing stock.
-- Apply with: php database/migrate_borrowing_cart.php
-- @when markers make the CLI runner resumable. Direct SQL import is for first application only.

CREATE TABLE IF NOT EXISTS borrowing_cart_items (
    user_id INT NOT NULL,
    equipment_id INT NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, equipment_id),
    INDEX idx_cart_equipment (equipment_id),
    CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_equipment FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id) ON DELETE CASCADE,
    CONSTRAINT chk_cart_quantity CHECK (quantity BETWEEN 1 AND 2147483647)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- This is both the grouped request header and the durable idempotency ledger.
CREATE TABLE IF NOT EXISTS borrowing_checkouts (
    checkout_id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_json LONGTEXT NULL,
    borrow_date DATE NOT NULL,
    expected_return_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (checkout_id),
    UNIQUE KEY uq_checkout_user_key (user_id, idempotency_key),
    INDEX idx_checkouts_user_date (user_id, created_at),
    CONSTRAINT fk_checkout_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT chk_checkout_dates CHECK (expected_return_date > borrow_date),
    CONSTRAINT chk_checkout_key CHECK (CHAR_LENGTH(idempotency_key) BETWEEN 16 AND 128),
    CONSTRAINT chk_checkout_hash CHECK (CHAR_LENGTH(payload_hash) = 64),
    CONSTRAINT chk_checkout_response CHECK (response_json IS NULL OR JSON_VALID(response_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @when column borrowing_requests checkout_id
ALTER TABLE borrowing_requests ADD COLUMN checkout_id INT NULL DEFAULT NULL;

-- @when index borrowing_requests uq_requests_checkout_equipment
ALTER TABLE borrowing_requests ADD UNIQUE KEY uq_requests_checkout_equipment (checkout_id, equipment_id);

-- @when constraint borrowing_requests fk_request_checkout
ALTER TABLE borrowing_requests ADD CONSTRAINT fk_request_checkout FOREIGN KEY (checkout_id) REFERENCES borrowing_checkouts(checkout_id) ON DELETE SET NULL;

-- @when constraint borrowing_requests chk_request_quantity_positive
ALTER TABLE borrowing_requests ADD CONSTRAINT chk_request_quantity_positive CHECK (requested_quantity >= 1);

-- @when constraint returns chk_return_quantity_positive
ALTER TABLE returns ADD CONSTRAINT chk_return_quantity_positive CHECK (returned_quantity >= 1);

-- @when constraint equipment chk_equipment_stock_bounds
ALTER TABLE equipment ADD CONSTRAINT chk_equipment_stock_bounds CHECK (total_quantity >= 1 AND available_quantity >= 0 AND available_quantity <= total_quantity);

-- Query support for existing owner/date lists and reservation accounting.
-- @when index borrowing_requests idx_requests_user_date
ALTER TABLE borrowing_requests ADD INDEX idx_requests_user_date (user_id, request_date);

-- @when index borrowing_requests idx_requests_equipment_status
ALTER TABLE borrowing_requests ADD INDEX idx_requests_equipment_status (equipment_id, status);

-- Retain cancelled item history instead of deleting borrowing records.
-- @when enum borrowing_requests cancelled
ALTER TABLE borrowing_requests MODIFY COLUMN status ENUM('pending','approved','rejected','returned','cancelled') NOT NULL DEFAULT 'pending';
