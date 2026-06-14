-- ============================================================
--  Muratina Café — Restaurant & Café POS System
--  MySQL schema + seed data
--  Import via Laragon / phpMyAdmin or: mysql -u root < schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS muratina_pos
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE muratina_pos;

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
--  Users & access control
-- ----------------------------------------------------------
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(120) NOT NULL,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  email         VARCHAR(150) DEFAULT NULL,
  phone         VARCHAR(30)  DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  passcode      VARCHAR(255) DEFAULT NULL, -- hashed PIN for quick waiter login
  role          ENUM('manager','cashier','inventory','waiter') NOT NULL DEFAULT 'cashier',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login    DATETIME     DEFAULT NULL,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP TABLE IF EXISTS login_history;
CREATE TABLE login_history (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT DEFAULT NULL,
  username   VARCHAR(60) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  success    TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id)
) ENGINE=InnoDB;

DROP TABLE IF EXISTS audit_logs;
CREATE TABLE audit_logs (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT DEFAULT NULL,
  action     VARCHAR(120) NOT NULL,
  details    VARCHAR(500) DEFAULT NULL,
  ip_address VARCHAR(45)  DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
--  Catalogue
-- ----------------------------------------------------------
DROP TABLE IF EXISTS categories;
CREATE TABLE categories (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80) NOT NULL UNIQUE,
  icon       VARCHAR(40) DEFAULT 'fa-mug-hot',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP TABLE IF EXISTS suppliers;
CREATE TABLE suppliers (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  phone      VARCHAR(30)  DEFAULT NULL,
  email      VARCHAR(150) DEFAULT NULL,
  address    VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP TABLE IF EXISTS products;
CREATE TABLE products (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(150) NOT NULL,
  sku            VARCHAR(60)  DEFAULT NULL,
  barcode        VARCHAR(60)  DEFAULT NULL,
  category_id    INT          DEFAULT NULL,
  supplier_id    INT          DEFAULT NULL,
  purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  selling_price  DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock_qty      INT          NOT NULL DEFAULT 0,
  low_stock      INT          NOT NULL DEFAULT 5,
  image          VARCHAR(255) DEFAULT NULL,
  expiry_date    DATE         DEFAULT NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (category_id),
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------
--  Customers
-- ----------------------------------------------------------
DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  phone          VARCHAR(30)  DEFAULT NULL,
  email          VARCHAR(150) DEFAULT NULL,
  loyalty_points INT NOT NULL DEFAULT 0,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------------------------------------
--  Sales
-- ----------------------------------------------------------
DROP TABLE IF EXISTS sales;
CREATE TABLE sales (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  receipt_no     VARCHAR(40) NOT NULL UNIQUE,
  user_id        INT DEFAULT NULL,
  customer_id    INT DEFAULT NULL,
  subtotal       DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount       DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax            DECIMAL(12,2) NOT NULL DEFAULT 0,
  total          DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid           DECIMAL(12,2) NOT NULL DEFAULT 0,
  change_due     DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_method ENUM('Cash','M-Pesa','Card','Bank Transfer') NOT NULL DEFAULT 'Cash',
  note           VARCHAR(255) DEFAULT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id), INDEX (created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

DROP TABLE IF EXISTS sale_items;
CREATE TABLE sale_items (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  sale_id      INT NOT NULL,
  product_id   INT DEFAULT NULL,
  product_name VARCHAR(150) NOT NULL,
  qty          INT NOT NULL DEFAULT 1,
  price        DECIMAL(12,2) NOT NULL DEFAULT 0,
  cost         DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total   DECIMAL(12,2) NOT NULL DEFAULT 0,
  INDEX (sale_id), INDEX (product_id),
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------
--  Inventory movements & purchases
-- ----------------------------------------------------------
DROP TABLE IF EXISTS stock_movements;
CREATE TABLE stock_movements (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT NOT NULL,
  type        ENUM('in','out','adjust') NOT NULL,
  qty         INT NOT NULL,
  reason      VARCHAR(150) DEFAULT NULL,
  user_id     INT DEFAULT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (product_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

DROP TABLE IF EXISTS attendance;
CREATE TABLE attendance (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  type       ENUM('in','out','break') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id), INDEX (created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

DROP TABLE IF EXISTS settings;
CREATE TABLE settings (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  company_name  VARCHAR(150) DEFAULT 'Muratina Café',
  logo          VARCHAR(255) DEFAULT NULL,
  currency      VARCHAR(10)  DEFAULT 'KSh',
  tax_rate      DECIMAL(5,2) DEFAULT 16.00,
  address       VARCHAR(255) DEFAULT 'Nairobi, Kenya',
  phone         VARCHAR(40)  DEFAULT '+254 700 000 000',
  email         VARCHAR(150) DEFAULT 'hello@muratinacafe.co.ke',
  receipt_footer VARCHAR(255) DEFAULT 'Thank you for dining with us. Karibu tena!'
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  Seed data
-- ============================================================

-- Default users. All passwords below are: Pass@123
-- (bcrypt hash generated with PHP password_hash)
-- Waiter passcodes below are the PINs: Brian = 1234, Aisha = 5678
INSERT INTO users (full_name, username, email, phone, password_hash, passcode, role) VALUES
('System Manager', 'admin',     'admin@muratinacafe.co.ke',  '+254700000001', '$2y$12$gFagdw0AGMmV8gBp/j6hsOHjcsu/hDTkXvv6GRfdJ34GFSdVz.2Xq', NULL, 'manager'),
('Jane Cashier',   'cashier',   'cashier@muratinacafe.co.ke','+254700000002', '$2y$12$gFagdw0AGMmV8gBp/j6hsOHjcsu/hDTkXvv6GRfdJ34GFSdVz.2Xq', NULL, 'cashier'),
('Mike Stocks',    'inventory', 'stock@muratinacafe.co.ke',  '+254700000003', '$2y$12$gFagdw0AGMmV8gBp/j6hsOHjcsu/hDTkXvv6GRfdJ34GFSdVz.2Xq', NULL, 'inventory'),
('Brian Waiter',   'brian',     NULL, '+254700000004', '$2y$12$gFagdw0AGMmV8gBp/j6hsOHjcsu/hDTkXvv6GRfdJ34GFSdVz.2Xq', '$2y$12$Mg/sHZuRwLLBEsxClMGO8efrU4JOS6drGamqOq3pgSBJ7OYsXDyo.', 'waiter'),
('Aisha Waiter',   'aisha',     NULL, '+254700000005', '$2y$12$gFagdw0AGMmV8gBp/j6hsOHjcsu/hDTkXvv6GRfdJ34GFSdVz.2Xq', '$2y$12$eFpuo3kSHk2LxyG3I4N/ou2miApGtspIpwxDbkTFn3om2pOR189l.', 'waiter');

INSERT INTO categories (name, icon) VALUES
('Coffee', 'fa-mug-hot'),
('Tea', 'fa-mug-saucer'),
('Fresh Juice', 'fa-glass-water'),
('Meals', 'fa-bowl-food'),
('Snacks', 'fa-cookie-bite'),
('Desserts', 'fa-ice-cream');

INSERT INTO suppliers (name, phone, email, address) VALUES
('Highland Coffee Roasters', '+254711111111', 'sales@highland.co.ke', 'Nyeri, Kenya'),
('FreshFarm Produce', '+254722222222', 'orders@freshfarm.co.ke', 'Limuru, Kenya'),
('City Bakers Ltd', '+254733333333', 'info@citybakers.co.ke', 'Nairobi, Kenya');

INSERT INTO products (name, sku, barcode, category_id, supplier_id, purchase_price, selling_price, stock_qty, low_stock) VALUES
('Espresso',            'COF-001', '6001000001', 1, 1, 60,  150, 200, 20),
('Cappuccino',          'COF-002', '6001000002', 1, 1, 90,  250, 150, 20),
('Cafe Latte',          'COF-003', '6001000003', 1, 1, 95,  260, 120, 20),
('Black Tea',           'TEA-001', '6001000010', 2, 2, 30,  100, 300, 30),
('Masala Chai',         'TEA-002', '6001000011', 2, 2, 45,  150, 80,  20),
('Fresh Orange Juice',  'JUI-001', '6001000020', 3, 2, 70,  200, 60,  15),
('Mango Smoothie',      'JUI-002', '6001000021', 3, 2, 90,  280, 40,  15),
('Avocado Juice',       'JUI-003', '6001000022', 3, 2, 85,  260, 8,   15),
('Beef Burger',         'MEA-001', '6001000030', 4, 3, 180, 450, 35,  10),
('Chicken Wrap',        'MEA-002', '6001000031', 4, 3, 160, 400, 25,  10),
('Pilau & Beef',        'MEA-003', '6001000032', 4, 2, 200, 520, 30,  10),
('Samosa',              'SNK-001', '6001000040', 5, 3, 20,  60,  120, 30),
('French Fries',        'SNK-002', '6001000041', 5, 3, 50,  180, 70,  20),
('Chocolate Cake',      'DES-001', '6001000050', 6, 3, 120, 350, 18,  10),
('Vanilla Ice Cream',   'DES-002', '6001000051', 6, 3, 80,  220, 5,   10);

INSERT INTO customers (name, phone, email, loyalty_points) VALUES
('Walk-in Customer', NULL, NULL, 0),
('Brian Otieno', '+254701234567', 'brian@example.com', 120),
('Aisha Mohamed', '+254702345678', 'aisha@example.com', 45);

INSERT INTO settings (company_name) VALUES ('Muratina Café');
