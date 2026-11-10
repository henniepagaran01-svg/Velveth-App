-- Velveth Lending Management System Database Schema
-- Run this SQL in your MySQL/phpMyAdmin to set up the database

CREATE DATABASE IF NOT EXISTS velveth_lending CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE velveth_lending;

-- USERS TABLE (Admin + Customers)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','customer') DEFAULT 'customer',
    status ENUM('active','inactive','suspended') DEFAULT 'active',
    avatar VARCHAR(255),
    address TEXT,
    reset_token VARCHAR(64) NULL,
    reset_token_expires DATETIME NULL,
    deleted_at DATETIME NULL DEFAULT NULL, -- soft delete: set when a customer is deleted; NULL = active. Restore from Archive & History.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Migration for existing installs that already have a users table without
-- these columns (run manually if upgrading an existing database):
--   ALTER TABLE users
--     ADD COLUMN reset_token VARCHAR(64) NULL,
--     ADD COLUMN reset_token_expires DATETIME NULL;
--
--   ALTER TABLE loan_applications
--     ADD COLUMN signed_name VARCHAR(150) NULL,
--     ADD COLUMN signed_at DATETIME NULL,
--     ADD COLUMN stated_monthly_income DECIMAL(15,2) NULL;
--
-- (Both of the above are also applied automatically at runtime by
--  ensureResetTokenColumns() / ensureLoanAppSignatureColumns() in
--  config.php, so this manual step is optional — kept here for reference.)

-- LOAN APPLICATIONS TABLE
CREATE TABLE IF NOT EXISTS loan_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    app_id VARCHAR(20) UNIQUE,
    customer_id INT NOT NULL,
    loan_amount DECIMAL(15,2) NOT NULL,
    loan_term INT NOT NULL COMMENT 'in months',
    interest_rate DECIMAL(5,2) DEFAULT 5.00,
    purpose TEXT,
    status ENUM('pending','approved','rejected','active','completed') DEFAULT 'pending',
    approved_by INT,
    approved_at DATETIME,
    monthly_payment DECIMAL(15,2),
    total_payable DECIMAL(15,2),
    remarks TEXT,
    signed_name VARCHAR(150) NULL,
    signed_at DATETIME NULL,
    stated_monthly_income DECIMAL(15,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ACTIVE LOANS TABLE
CREATE TABLE IF NOT EXISTS loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    customer_id INT NOT NULL,
    loan_amount DECIMAL(15,2) NOT NULL,
    amount_paid DECIMAL(15,2) DEFAULT 0.00,
    outstanding_balance DECIMAL(15,2),
    monthly_payment DECIMAL(15,2),
    next_payment_date DATE,
    start_date DATE,
    end_date DATE,
    status ENUM('active','completed','defaulted') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES loan_applications(id),
    FOREIGN KEY (customer_id) REFERENCES users(id)
);

-- PAYMENTS TABLE
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id VARCHAR(20) UNIQUE,
    loan_id INT NOT NULL,
    customer_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_method ENUM('cash','gcash','bank_transfer','bank','cash_collection','check') DEFAULT 'cash',
    payment_date DATE NOT NULL,
    status ENUM('pending','confirmed','failed') DEFAULT 'pending',
    received_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (customer_id) REFERENCES users(id)
);

-- PENALTIES TABLE
CREATE TABLE IF NOT EXISTS penalties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    customer_id INT NOT NULL,
    penalty_amount DECIMAL(15,2) NOT NULL,
    reason VARCHAR(255),
    due_date DATE,
    status ENUM('pending','paid','waived') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (customer_id) REFERENCES users(id)
);

-- DOCUMENTS TABLE
CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_id VARCHAR(20) UNIQUE,
    customer_id INT NOT NULL,
    application_id INT,
    doc_type VARCHAR(100),
    file_name VARCHAR(255),
    file_path VARCHAR(255),
    status ENUM('pending','verified','rejected') DEFAULT 'pending',
    reviewed_by INT,
    reviewed_at DATETIME,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id),
    FOREIGN KEY (application_id) REFERENCES loan_applications(id)
);

-- NOTIFICATIONS TABLE
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255),
    message TEXT,
    type ENUM('info','success','warning','danger') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- AUDIT TRAIL TABLE
CREATE TABLE IF NOT EXISTS audit_trail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('Loan','Payment','Customer','Penalty','Document','User','System') DEFAULT 'System',
    user_name VARCHAR(100),
    action VARCHAR(255),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Chat between borrower and admin -- one thread per customer
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    sender_role ENUM('customer','admin') NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id)
);
-- (Also created automatically at runtime by ensureMessagesTable() in
--  config.php, so this is optional on an already-deployed database.)

-- SEED DATA: Admin User
INSERT INTO users (full_name, email, phone, password, role, status) VALUES
('Admin User', 'admin@velveth.com', '+63 912 000 0000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active');
-- Default admin password: password

-- SEED DATA: Sample Customers
INSERT INTO users (full_name, email, phone, password, role, status) VALUES
('Harley Armand', 'harley@email.com', '+63 905960431', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active'),
('Hannie Stroha', 'hannie@email.com', '+63 905175468', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active'),
('Jenny Williams', 'jenny@email.com', '+63 919612465', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active'),
('Boldem Johnson', 'boldem@email.com', '+63 903612469', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active'),
('Danta Grunda', 'danta@email.com', '+63 919940618', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active');

-- SEED DATA: Loan Applications
INSERT INTO loan_applications (app_id, customer_id, loan_amount, loan_term, interest_rate, purpose, status, monthly_payment, total_payable) VALUES
('APP-001', 2, 50000.00, 12, 5.00, 'Business Capital', 'active', 4583.33, 55000.00),
('APP-002', 3, 30000.00, 6, 5.00, 'Medical Expenses', 'active', 5250.00, 31500.00),
('APP-003', 4, 80000.00, 24, 5.00, 'Home Improvement', 'pending', 3500.00, 84000.00),
('APP-004', 5, 20000.00, 3, 5.00, 'Education', 'pending', 7000.00, 21000.00),
('APP-005', 6, 150000.00, 36, 5.00, 'Business Expansion', 'approved', 4583.33, 157500.00);

-- SEED DATA: Active Loans
INSERT INTO loans (application_id, customer_id, loan_amount, amount_paid, outstanding_balance, monthly_payment, next_payment_date, start_date, end_date, status) VALUES
(1, 2, 50000.00, 10000.00, 40000.00, 4583.33, '2026-03-10', '2025-03-10', '2026-03-10', 'active'),
(2, 3, 30000.00, 5250.00, 24750.00, 5250.00, '2026-03-15', '2025-09-15', '2026-03-15', 'active');

-- SEED DATA: Payments
INSERT INTO payments (payment_id, loan_id, customer_id, amount, payment_method, payment_date, status) VALUES
('PAY-001', 1, 2, 5000.00, 'cash', '2026-02-01', 'confirmed'),
('PAY-002', 1, 2, 5000.00, 'gcash', '2026-01-15', 'confirmed'),
('PAY-003', 2, 3, 5250.00, 'bank_transfer', '2026-01-20', 'confirmed');

-- SEED DATA: Documents
INSERT INTO documents (doc_id, customer_id, application_id, doc_type, file_name, status) VALUES
('DOC-001', 2, 1, 'Government ID', 'gov_id_harley.jpg', 'verified'),
('DOC-002', 2, 1, 'Proof of Income', 'income_harley.pdf', 'verified'),
('DOC-003', 3, 2, 'Government ID', 'gov_id_hannie.jpg', 'pending'),
('DOC-004', 4, 3, 'Business Permit', 'permit_jenny.pdf', 'pending'),
('DOC-005', 5, 4, 'Government ID', 'gov_id_boldem.jpg', 'rejected');

-- SEED DATA: Notifications
INSERT INTO notifications (user_id, title, message, type) VALUES
(2, 'Payment Received', 'Your payment of ₱5,000 has been recorded.', 'success'),
(2, 'Loan Approved', 'Your loan application APP-001 has been approved.', 'success'),
(2, 'Payment Reminder', 'Your next payment of ₱4,583.33 is due on March 10, 2026.', 'warning');

-- SEED DATA: Audit Trail
INSERT INTO audit_trail (type, user_name, action, details, created_at) VALUES
('Loan', 'Admin User', 'Approved loan application', 'APP-001 for Harley Armand', '2026-02-18 10:15:00'),
('Payment', 'Staff Member', 'Recorded payment', 'PAY-001 for Sophia Diaz', '2026-02-16 11:00:00'),
('Payment', 'Staff Member', 'Recorded payment', 'PAY-002 for Michael Flores', '2026-02-15 02:20:00'),
('Customer', 'Manager', 'Updated customer information', 'CUST-003', '2026-02-14 09:00:00'),
('Loan', 'Admin User', 'Rejected loan application', 'APP-007 for Lisa Baldwin', '2026-01-28 09:20:00'),
('Penalty', 'Staff Member', 'Recorded penalty', 'PEN-007 for Maricel Bautista', '2026-01-17 09:40:00'),
('User', 'Admin User', 'Created new user account', 'USR-008', '2026-01-15 04:15:00');

-- ─────────────────────────────────────────────────────────────────────────
-- ADDITIONAL DEMO DATA — more customers, applications across every status,
-- a multi-month payment history, and real penalty records (used to
-- demonstrate the "unpaid penalty" flag shown on the Payments list).
-- Safe to skip on a production database; intended for demo/testing.
-- ─────────────────────────────────────────────────────────────────────────

-- More sample customers (ids continue from the 5 above)
INSERT INTO users (full_name, email, phone, password, role, status) VALUES
('Maricel Bautista', 'maricel@email.com', '+63 917 220 3391', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active'),
('Kevin Ramos', 'kevin@email.com', '+63 918 552 6674', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active'),
('Grace Villanueva', 'grace@email.com', '+63 906 774 1120', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active'),
('Paolo Mendoza', 'paolo@email.com', '+63 920 335 8842', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active'),
('Aina Cruz', 'aina@email.com', '+63 915 662 0093', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'inactive'),
('Ronnel Torres', 'ronnel@email.com', '+63 908 441 7765', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'suspended'),
('Ivy Santos', 'ivy@email.com', '+63 921 990 4432', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active'),
('Mark Dela Cruz', 'mark@email.com', '+63 917 003 6621', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'active');

-- More loan applications — covering every status (pending/approved/rejected/active/completed)
INSERT INTO loan_applications (app_id, customer_id, loan_amount, loan_term, interest_rate, purpose, status, monthly_payment, total_payable) VALUES
('APP-006', 7,  25000.00,  6, 5.00, 'Emergency Fund',       'completed', 4375.00,  26250.00),
('APP-007', 8,  60000.00, 12, 5.00, 'Vehicle Purchase',     'active',    5250.00,  63000.00),
('APP-008', 9,  45000.00, 18, 5.00, 'Debt Consolidation',   'active',    2625.00,  47250.00),
('APP-009', 10, 15000.00,  3, 5.00, 'Education',            'rejected',  5250.00,  15750.00),
('APP-010', 11, 35000.00, 12, 5.00, 'Home Improvement',     'rejected',  3062.50,  36750.00),
('APP-011', 13, 100000.00,24, 5.00, 'Business Capital',     'pending',   4375.00, 105000.00),
('APP-012', 3,  10000.00,  3, 5.00, 'Medical Expenses',     'completed', 3500.00,  10500.00),
('APP-013', 14, 20000.00,  6, 5.00, 'Business Capital',     'approved',  3500.00,  21000.00);

-- Loans created for the active/completed applications above
INSERT INTO loans (application_id, customer_id, loan_amount, amount_paid, outstanding_balance, monthly_payment, next_payment_date, start_date, end_date, status) VALUES
(6,  7, 25000.00, 25000.00,     0.00, 4375.00, '2025-12-05', '2025-06-05', '2025-12-05', 'completed'),
(7,  8, 60000.00, 15750.00, 44250.00, 5250.00, '2026-03-01', '2025-06-01', '2026-06-01', 'active'),
(8,  9, 45000.00,  5250.00, 39750.00, 2625.00, '2026-03-20', '2025-11-20', '2027-05-20', 'active'),
(12, 3, 10000.00, 10000.00,     0.00, 3500.00, '2025-11-15', '2025-08-15', '2025-11-15', 'completed');

-- Multi-month payment history (spans Jun 2025 – Mar 2026) for a richer
-- Monthly Collections chart, plus a pending and a failed payment for
-- realistic status variety.
INSERT INTO payments (payment_id, loan_id, customer_id, amount, payment_method, payment_date, status) VALUES
('PAY-004', 3, 7, 4375.00, 'cash',          '2025-07-05', 'confirmed'),
('PAY-005', 3, 7, 4375.00, 'gcash',         '2025-08-05', 'confirmed'),
('PAY-006', 3, 7, 4375.00, 'bank_transfer', '2025-09-05', 'confirmed'),
('PAY-007', 3, 7, 4375.00, 'cash',          '2025-10-05', 'confirmed'),
('PAY-008', 3, 7, 4375.00, 'gcash',         '2025-11-05', 'confirmed'),
('PAY-009', 3, 7, 4375.00, 'cash',          '2025-12-05', 'confirmed'),
('PAY-010', 4, 8, 5250.00, 'gcash',         '2025-12-01', 'confirmed'),
('PAY-011', 4, 8, 5250.00, 'cash',          '2026-01-01', 'confirmed'),
('PAY-012', 4, 8, 5250.00, 'bank_transfer', '2026-02-01', 'confirmed'),
('PAY-013', 5, 9, 2625.00, 'gcash',         '2025-12-20', 'confirmed'),
('PAY-014', 5, 9, 2625.00, 'cash',          '2026-01-20', 'confirmed'),
('PAY-015', 6, 3, 3500.00, 'cash',          '2025-09-15', 'confirmed'),
('PAY-016', 6, 3, 3500.00, 'gcash',         '2025-10-15', 'confirmed'),
('PAY-017', 6, 3, 3500.00, 'bank_transfer', '2025-11-15', 'confirmed'),
('PAY-018', 4, 8, 5250.00, 'gcash',         '2026-03-01', 'pending'),
('PAY-019', 5, 9, 2625.00, 'cash',          '2026-02-25', 'failed');

-- Penalties — a real mix of pending / paid / waived so the "unpaid penalty"
-- flag on the Payments list actually has something to demonstrate.
INSERT INTO penalties (loan_id, customer_id, penalty_amount, reason, due_date, status) VALUES
(4, 8, 250.00, 'Late payment — 5 days overdue',                     '2026-02-25', 'pending'),
(5, 9, 150.00, 'Late payment — 3 days overdue',                     '2026-01-25', 'paid'),
(1, 2, 100.00, 'Late payment — 2 days overdue',                     '2026-01-12', 'paid'),
(2, 3, 300.00, 'Late payment — 6 days overdue, +5% weekly surcharge','2025-12-20', 'waived');

-- More documents, matching the additional applications above
INSERT INTO documents (doc_id, customer_id, application_id, doc_type, file_name, status) VALUES
('DOC-006', 7,  6, 'Government ID',   'gov_id_maricel.jpg',  'verified'),
('DOC-007', 7,  6, 'Selfie with ID',  'selfie_maricel.jpg',  'verified'),
('DOC-008', 8,  7, 'Government ID',   'gov_id_kevin.jpg',    'verified'),
('DOC-009', 8,  7, 'Selfie with ID',  'selfie_kevin.jpg',    'verified'),
('DOC-010', 9,  8, 'Government ID',   'gov_id_grace.jpg',    'verified'),
('DOC-011', 9,  8, 'Proof of Income', 'income_grace.pdf',    'pending'),
('DOC-012', 10, 9, 'Government ID',   'gov_id_paolo.jpg',    'rejected'),
('DOC-013', 11, 10,'Government ID',   'gov_id_aina.jpg',     'rejected'),
('DOC-014', 13, 11,'Government ID',   'gov_id_ivy.jpg',      'pending'),
('DOC-015', 13, 11,'Selfie with ID',  'selfie_ivy.jpg',      'pending'),
('DOC-016', 14, 13,'Government ID',   'gov_id_mark.jpg',     'verified');

-- More notifications reflecting the activity above
INSERT INTO notifications (user_id, title, message, type) VALUES
(8,  'Penalty Issued', 'A late payment penalty of ₱250.00 has been added to your account.', 'warning'),
(9,  'Payment Failed', 'Your payment of ₱2,625.00 on Feb 25, 2026 could not be processed. Please try again.', 'danger'),
(7,  'Loan Completed', 'Congratulations! Your loan APP-006 has been fully paid off.', 'success'),
(3,  'Loan Completed', 'Congratulations! Your loan APP-012 has been fully paid off.', 'success'),
(13, 'Application Received', 'Your loan application APP-011 has been received and is under review.', 'info'),
(1,  'New Application', 'Ivy Santos submitted APP-011 for ₱100,000.00 (Business Capital).', 'info');

-- More audit trail entries reflecting the activity above
INSERT INTO audit_trail (type, user_name, action, details, created_at) VALUES
('Loan',    'Admin User',    'Marked loan as completed',   'APP-006 for Maricel Bautista — fully paid', '2025-12-05 14:30:00'),
('Loan',    'Admin User',    'Marked loan as completed',   'APP-012 for Hannie Stroha — fully paid',    '2025-11-15 10:05:00'),
('Loan',    'Admin User',    'Rejected loan application',  'APP-009 for Paolo Mendoza — incomplete documents', '2026-01-05 09:15:00'),
('Loan',    'Admin User',    'Rejected loan application',  'APP-010 for Aina Cruz — unverifiable ID',   '2026-01-08 11:40:00'),
('Loan',    'Admin User',    'Approved loan application',  'APP-013 for Mark Dela Cruz',                '2026-02-10 13:20:00'),
('Penalty', 'Staff Member',  'Issued penalty',             'PEN for Kevin Ramos — ₱250.00 late payment','2026-02-25 08:00:00'),
('Penalty', 'Admin User',    'Waived penalty',             'PEN for Hannie Stroha — ₱300.00 waived',    '2025-12-21 16:10:00'),
('Payment', 'Staff Member',  'Recorded payment',           'PAY-012 for Kevin Ramos',                   '2026-02-01 09:30:00'),
('Payment', 'Staff Member',  'Payment failed',             'PAY-019 for Grace Villanueva',              '2026-02-25 15:45:00');

-- ─────────────────────────────────────────────────────────────────────────
-- v17 ADDITIONS — Run these ALTER statements if upgrading from the original
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS date_of_birth DATE            AFTER address,
  ADD COLUMN IF NOT EXISTS gender        VARCHAR(20)     AFTER date_of_birth,
  ADD COLUMN IF NOT EXISTS civil_status  VARCHAR(20)     AFTER gender,
  ADD COLUMN IF NOT EXISTS id_type       VARCHAR(50)     AFTER civil_status,
  ADD COLUMN IF NOT EXISTS email_notifications TINYINT(1) DEFAULT 1 AFTER id_type,
  ADD COLUMN IF NOT EXISTS sms_notifications   TINYINT(1) DEFAULT 0 AFTER email_notifications;

ALTER TABLE loans
  ADD COLUMN IF NOT EXISTS loan_term    INT             AFTER monthly_payment,
  ADD COLUMN IF NOT EXISTS interest_rate DECIMAL(5,2)  DEFAULT 5.00 AFTER loan_term;

ALTER TABLE payments
  MODIFY COLUMN payment_method ENUM('cash','gcash','bank_transfer','bank','check') DEFAULT 'cash';

ALTER TABLE penalties
  ADD COLUMN IF NOT EXISTS penalty_id VARCHAR(20) UNIQUE AFTER id;

ALTER TABLE audit_trail
  MODIFY COLUMN type ENUM('Loan','Payment','Customer','Penalty','Document','User','System','Profile','Security') DEFAULT 'System';

-- v17 FIX: Add confirmed_at column and expand payment_method ENUM
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS confirmed_at TIMESTAMP NULL DEFAULT NULL AFTER status;

ALTER TABLE payments
  MODIFY COLUMN payment_method ENUM('cash','gcash','bank_transfer','bank','cash_collection','check') DEFAULT 'cash';

ALTER TABLE payments
  MODIFY COLUMN status ENUM('pending','confirmed','failed') DEFAULT 'pending';
