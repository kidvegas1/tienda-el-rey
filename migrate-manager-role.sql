-- Add manager role for store-locked accounts (Phase 1)
ALTER TABLE users MODIFY role ENUM('admin','manager','cashier','employee') NOT NULL DEFAULT 'employee';
