-- ================================================================
-- Bright on Beans Coffee House Inventory Sales Tracking System
-- Database Schema
-- ================================================================

CREATE DATABASE IF NOT EXISTS bright_on_beans
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE bright_on_beans;

-- ================================================================
-- 1. USERS TABLE
-- Stores admin and staff accounts with role-based access
-- ================================================================
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 2. SECRET QUESTIONS TABLE
-- Stores 5 security questions per staff member for password recovery
-- ================================================================
CREATE TABLE IF NOT EXISTS secret_questions (
    sq_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    question_number TINYINT NOT NULL,
    question VARCHAR(255) NOT NULL,
    answer VARCHAR(255) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_question (user_id, question_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 3. PRODUCTS TABLE
-- Master list of all menu items offered by the coffee house
-- ================================================================
CREATE TABLE IF NOT EXISTS products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    status ENUM('available', 'unavailable') NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_category (category),
    INDEX idx_products_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 4. INGREDIENTS TABLE
-- Raw materials inventory with stock levels and minimum thresholds
-- ================================================================
CREATE TABLE IF NOT EXISTS ingredients (
    ingredient_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    current_stock DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    minimum_level DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    unit_cost DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    expiration_date DATE DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 5. RECIPE INGREDIENTS TABLE
-- Junction table linking products to ingredients with per-serving quantities
-- ================================================================
CREATE TABLE IF NOT EXISTS recipe_ingredients (
    recipe_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    ingredient_id INT NOT NULL,
    quantity_per_serving DECIMAL(10,3) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id) ON DELETE CASCADE,
    UNIQUE KEY unique_product_ingredient (product_id, ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 6. SALES TRANSACTIONS TABLE
-- Records completed sale events with totals and user references
-- ================================================================
CREATE TABLE IF NOT EXISTS sales_transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(12,2) NOT NULL,
    total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('completed', 'voided') NOT NULL DEFAULT 'completed',
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    INDEX idx_sales_date (transaction_date),
    INDEX idx_sales_user (user_id),
    INDEX idx_sales_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 7. SALES ITEMS TABLE
-- Individual items within each sales transaction
-- ================================================================
CREATE TABLE IF NOT EXISTS sales_items (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (transaction_id) REFERENCES sales_transactions(transaction_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    INDEX idx_items_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 8. INVENTORY HISTORY TABLE
-- Automatic log of all stock-level changes (from sales or adjustments)
-- ================================================================
CREATE TABLE IF NOT EXISTS inventory_history (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_id INT NOT NULL,
    stock_before DECIMAL(12,3) NOT NULL,
    stock_after DECIMAL(12,3) NOT NULL,
    change_quantity DECIMAL(12,3) NOT NULL,
    change_type ENUM('sale_deduction', 'adjustment_add', 'adjustment_deduct', 'pallet_in', 'pallet_out') NOT NULL,
    reference_id INT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id),
    INDEX idx_history_ingredient (ingredient_id),
    INDEX idx_history_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 9. INVENTORY ADJUSTMENTS TABLE
-- Manual stock corrections by authorized users
-- ================================================================
CREATE TABLE IF NOT EXISTS inventory_adjustments (
    adjustment_id INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_id INT NOT NULL,
    user_id INT NOT NULL,
    adjustment_type ENUM('add', 'deduct') NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    balance_after DECIMAL(12,3) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    INDEX idx_adjustments_ingredient (ingredient_id),
    INDEX idx_adjustments_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 10. PALLETS TABLE (PIPO - Reserved & Batch Stock Tracking)
-- Stores individual inbound pallets, batch/lot #, rack location,
-- unit cost, and individual batch expiration dates.
-- ================================================================
CREATE TABLE IF NOT EXISTS pallets (
    pallet_id INT AUTO_INCREMENT PRIMARY KEY,
    pallet_code VARCHAR(50) NOT NULL UNIQUE,
    ingredient_id INT NOT NULL,
    batch_number VARCHAR(100) DEFAULT NULL,
    storage_location VARCHAR(100) NOT NULL DEFAULT 'Pallet Rack A-01',
    supplier VARCHAR(150) DEFAULT NULL,
    initial_quantity DECIMAL(12,3) NOT NULL,
    remaining_quantity DECIMAL(12,3) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    unit_cost DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('stored', 'in_use', 'depleted', 'quarantined') NOT NULL DEFAULT 'stored',
    received_date DATE NOT NULL,
    expiration_date DATE DEFAULT NULL,
    created_by INT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    INDEX idx_pallets_ingredient (ingredient_id),
    INDEX idx_pallets_status (status),
    INDEX idx_pallets_expiration (expiration_date),
    INDEX idx_pallets_location (storage_location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- 11. PALLET MOVEMENTS TABLE (PIPO Audit Ledger)
-- Tracks all Pallet In, Stage to Active Bar, Pallet Out, Relocations
-- ================================================================
CREATE TABLE IF NOT EXISTS pallet_movements (
    movement_id INT AUTO_INCREMENT PRIMARY KEY,
    pallet_id INT NOT NULL,
    movement_type ENUM('pallet_in', 'pallet_out', 'relocation', 'adjustment') NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    quantity_before DECIMAL(12,3) NOT NULL,
    quantity_after DECIMAL(12,3) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    source_location VARCHAR(100) DEFAULT NULL,
    destination VARCHAR(150) DEFAULT NULL,
    purpose_reason VARCHAR(255) DEFAULT NULL,
    user_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pallet_id) REFERENCES pallets(pallet_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    INDEX idx_movement_pallet (pallet_id),
    INDEX idx_movement_type (movement_type),
    INDEX idx_movement_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

