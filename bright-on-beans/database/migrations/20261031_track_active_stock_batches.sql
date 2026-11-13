CREATE TABLE IF NOT EXISTS ingredient_stock_batches (
    batch_id INT NOT NULL AUTO_INCREMENT,
    ingredient_id INT NOT NULL,
    pallet_id INT DEFAULT NULL,
    remaining_quantity DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    expiration_date DATE DEFAULT NULL,
    unit_cost DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (batch_id),
    KEY idx_active_batches_ingredient (ingredient_id),
    KEY idx_active_batches_expiration (expiration_date),
    CONSTRAINT active_batches_ingredient_fk
        FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_stock_deductions (
    deduction_id INT NOT NULL AUTO_INCREMENT,
    sales_item_id INT NOT NULL,
    ingredient_id INT NOT NULL,
    batch_id INT DEFAULT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    PRIMARY KEY (deduction_id),
    KEY idx_sale_deductions_item (sales_item_id),
    KEY idx_sale_deductions_batch (batch_id),
    CONSTRAINT sale_deductions_item_fk
        FOREIGN KEY (sales_item_id) REFERENCES sales_items (item_id) ON DELETE CASCADE,
    CONSTRAINT sale_deductions_ingredient_fk
        FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id),
    CONSTRAINT sale_deductions_batch_fk
        FOREIGN KEY (batch_id) REFERENCES ingredient_stock_batches (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ingredients
    ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0;
