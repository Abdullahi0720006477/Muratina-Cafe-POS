-- ============================================================
--  Migration: add Waiter role + passcode (PIN) login
--  Run this ONLY if you imported schema.sql before the waiter
--  feature was added. Fresh imports already include these changes.
-- ============================================================
USE muratina_pos;

-- Add the hashed-passcode column for quick waiter login
ALTER TABLE users
  ADD COLUMN passcode VARCHAR(255) DEFAULT NULL AFTER password_hash;

-- Add 'waiter' to the role enum
ALTER TABLE users
  MODIFY COLUMN role ENUM('manager','cashier','inventory','waiter') NOT NULL DEFAULT 'cashier';

-- Optional: two demo waiters (PINs: Brian = 1234, Aisha = 5678)
INSERT INTO users (full_name, username, email, phone, password_hash, passcode, role) VALUES
('Brian Waiter', 'brian', NULL, '+254700000004', '$2y$12$gFagdw0AGMmV8gBp/j6hsOHjcsu/hDTkXvv6GRfdJ34GFSdVz.2Xq', '$2y$12$Mg/sHZuRwLLBEsxClMGO8efrU4JOS6drGamqOq3pgSBJ7OYsXDyo.', 'waiter'),
('Aisha Waiter', 'aisha', NULL, '+254700000005', '$2y$12$gFagdw0AGMmV8gBp/j6hsOHjcsu/hDTkXvv6GRfdJ34GFSdVz.2Xq', '$2y$12$eFpuo3kSHk2LxyG3I4N/ou2miApGtspIpwxDbkTFn3om2pOR189l.', 'waiter');
