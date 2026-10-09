-- ============================================================
-- Equipment Borrowing and Management System
-- Database Schema (MySQL / MariaDB — for XAMPP + phpMyAdmin)
-- ============================================================
-- In phpMyAdmin: create a database (e.g. "equipment_borrowing_system"),
-- select it, open the SQL tab, paste this whole file, and run it.

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS equipment_condition_reports;
DROP TABLE IF EXISTS returns;
DROP TABLE IF EXISTS borrowing_requests;
DROP TABLE IF EXISTS equipment;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- 1. ROLES
-- ------------------------------------------------------------
CREATE TABLE roles (
    role_id    INT AUTO_INCREMENT PRIMARY KEY,
    role_name  VARCHAR(20) NOT NULL UNIQUE      -- 'admin' | 'staff' | 'customer'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. USERS
-- ------------------------------------------------------------
CREATE TABLE users (
    user_id        INT AUTO_INCREMENT PRIMARY KEY,
    role_id        INT NOT NULL,
    name           VARCHAR(100) NOT NULL,
    email          VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,        -- bcrypt hash, never plain text
    failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until   DATETIME NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id)
        REFERENCES roles(role_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. CATEGORIES
-- ------------------------------------------------------------
CREATE TABLE categories (
    category_id   INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,
    description   TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4. EQUIPMENT
-- ------------------------------------------------------------
CREATE TABLE equipment (
    equipment_id   INT AUTO_INCREMENT PRIMARY KEY,
    equipment_name VARCHAR(150) NOT NULL,
    description    TEXT,
    serial_number  VARCHAR(100) UNIQUE,
    category_id    INT NULL,
    total_quantity  INT UNSIGNED NOT NULL DEFAULT 1,
    available_quantity INT UNSIGNED NOT NULL DEFAULT 1,
    borrowing_time_limit_days INT UNSIGNED NOT NULL DEFAULT 7,
    status         ENUM('available', 'borrowed', 'maintenance', 'pending') NOT NULL DEFAULT 'available',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_equipment_category FOREIGN KEY (category_id)
        REFERENCES categories(category_id) ON DELETE RESTRICT,
    CONSTRAINT chk_equipment_borrowing_limit CHECK (borrowing_time_limit_days BETWEEN 1 AND 3650),
    CONSTRAINT chk_equipment_quantities CHECK (available_quantity <= total_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5. BORROWING_REQUESTS
-- ------------------------------------------------------------
CREATE TABLE borrowing_requests (
    request_id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id               INT NOT NULL,
    equipment_id          INT NOT NULL,
    request_date          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    borrow_date           DATE,
    expected_return_date  DATE,
    requested_quantity    INT UNSIGNED NOT NULL DEFAULT 1,
    status                ENUM('pending', 'approved', 'rejected', 'returned', 'cancelled', 'borrowed') NOT NULL DEFAULT 'pending',
    picked_up_at          DATETIME NULL DEFAULT NULL,
    picked_up_by_staff_id INT NULL DEFAULT NULL,
    CONSTRAINT fk_request_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_request_equipment FOREIGN KEY (equipment_id)
        REFERENCES equipment(equipment_id) ON DELETE CASCADE,
    CONSTRAINT fk_request_pickup_staff FOREIGN KEY (picked_up_by_staff_id)
        REFERENCES users(user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_request_quantity_positive CHECK (requested_quantity >= 1),
    CONSTRAINT chk_request_pickup_evidence CHECK ((picked_up_at IS NULL AND picked_up_by_staff_id IS NULL AND status <> 'borrowed') OR (picked_up_at IS NOT NULL AND picked_up_by_staff_id IS NOT NULL AND status IN ('borrowed','returned'))),
    CONSTRAINT chk_request_pickup_dates CHECK (picked_up_at IS NULL OR (borrow_date IS NOT NULL AND expected_return_date IS NOT NULL AND DATE(picked_up_at) >= borrow_date AND DATE(picked_up_at) <= expected_return_date))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6. RETURNS
-- ------------------------------------------------------------
CREATE TABLE returns (
    return_id             INT AUTO_INCREMENT PRIMARY KEY,
    request_id            INT NOT NULL UNIQUE,
    processed_by_staff_id INT NULL,
    actual_return_date    DATE NOT NULL DEFAULT (CURRENT_DATE),
    returned_quantity     INT UNSIGNED NOT NULL DEFAULT 1,
    remarks               TEXT,
    CONSTRAINT fk_return_request FOREIGN KEY (request_id)
        REFERENCES borrowing_requests(request_id) ON DELETE CASCADE,
    CONSTRAINT fk_return_staff FOREIGN KEY (processed_by_staff_id)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_return_quantity_positive CHECK (returned_quantity >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 7. EQUIPMENT_CONDITION_REPORTS
-- ------------------------------------------------------------
CREATE TABLE equipment_condition_reports (
    report_id            INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id         INT NOT NULL,
    reported_by_user_id  INT NULL,
    condition_status     ENUM('good', 'damaged', 'missing', 'under_repair') NOT NULL DEFAULT 'good',
    notes                TEXT,
    logged_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_condition_equipment FOREIGN KEY (equipment_id)
        REFERENCES equipment(equipment_id) ON DELETE CASCADE,
    CONSTRAINT fk_condition_reporter FOREIGN KEY (reported_by_user_id)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 8. NOTIFICATIONS
-- ------------------------------------------------------------
CREATE TABLE notifications (
    notification_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT NOT NULL,
    message           VARCHAR(255) NOT NULL,
    is_read           TINYINT(1) NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Helpful indexes
-- ------------------------------------------------------------
CREATE INDEX idx_users_role ON users(role_id);
CREATE INDEX idx_equipment_category ON equipment(category_id);
CREATE INDEX idx_requests_user ON borrowing_requests(user_id);
CREATE INDEX idx_requests_equipment ON borrowing_requests(equipment_id);
CREATE INDEX idx_requests_status ON borrowing_requests(status);
CREATE INDEX idx_requests_pickup_staff ON borrowing_requests(picked_up_by_staff_id);
CREATE INDEX idx_requests_user_status_date ON borrowing_requests(user_id,status,request_date);
CREATE INDEX idx_condition_equipment ON equipment_condition_reports(equipment_id);
CREATE INDEX idx_notifications_user ON notifications(user_id, is_read);

-- ------------------------------------------------------------
-- Seed data
-- ------------------------------------------------------------
INSERT INTO roles (role_name) VALUES ('admin'), ('staff'), ('customer');

INSERT INTO categories (category_name, description) VALUES
 ('Audio/Visual', 'Projectors, speakers, microphones'),
 ('Computing', 'Laptops, tablets, accessories'),
 ('Sports', 'Sports and recreational equipment');

INSERT INTO equipment (equipment_name, description, serial_number, category_id, status) VALUES
 ('Epson Projector', 'Portable HD projector', 'EPS-0001', 1, 'available'),
 ('Dell Laptop', 'Core i5, 8GB RAM', 'DELL-0001', 2, 'available'),
 ('Basketball', 'Official size 7', 'BBL-0001', 3, 'available');

-- NOTE: No default admin user is seeded here because a bcrypt hash must be
-- generated by PHP's password_hash(), not typed by hand. After running this
-- schema, create the first admin account by running database/create_admin.php
-- once (see README.md).
