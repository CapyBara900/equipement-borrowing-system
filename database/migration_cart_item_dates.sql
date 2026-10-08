-- Dated cart entries. Apply using migrate_cart_item_dates.php for safe reruns.
-- Existing undated cart rows remain drafts; dates are never invented.
-- @when column borrowing_cart_items cart_item_id
ALTER TABLE borrowing_cart_items ADD COLUMN cart_item_id INT NOT NULL AUTO_INCREMENT, ADD UNIQUE KEY uq_cart_entry_id (cart_item_id);
-- @when column borrowing_cart_items borrow_date
ALTER TABLE borrowing_cart_items ADD COLUMN borrow_date DATE NULL DEFAULT NULL;
-- @when column borrowing_cart_items expected_return_date
ALTER TABLE borrowing_cart_items ADD COLUMN expected_return_date DATE NULL DEFAULT NULL;
-- @when index borrowing_cart_items uq_cart_customer_equipment_dates
ALTER TABLE borrowing_cart_items ADD UNIQUE KEY uq_cart_customer_equipment_dates (user_id,equipment_id,borrow_date,expected_return_date);
-- @when primary borrowing_cart_items cart_item_id
ALTER TABLE borrowing_cart_items DROP PRIMARY KEY, ADD PRIMARY KEY (cart_item_id);
-- @when index-present borrowing_cart_items uq_cart_entry_id
ALTER TABLE borrowing_cart_items DROP INDEX uq_cart_entry_id;
-- @when constraint borrowing_cart_items chk_cart_dates
ALTER TABLE borrowing_cart_items ADD CONSTRAINT chk_cart_dates CHECK ((borrow_date IS NULL AND expected_return_date IS NULL) OR (borrow_date IS NOT NULL AND expected_return_date IS NOT NULL AND expected_return_date >= borrow_date));
-- @when index borrowing_requests uq_requests_checkout_dates
ALTER TABLE borrowing_requests ADD UNIQUE KEY uq_requests_checkout_dates (checkout_id,equipment_id,borrow_date,expected_return_date);
-- @when index-present borrowing_requests uq_requests_checkout_equipment
ALTER TABLE borrowing_requests DROP INDEX uq_requests_checkout_equipment;
-- @when same-day borrowing_checkouts chk_checkout_dates
ALTER TABLE borrowing_checkouts DROP CONSTRAINT chk_checkout_dates, ADD CONSTRAINT chk_checkout_dates CHECK (expected_return_date >= borrow_date);
