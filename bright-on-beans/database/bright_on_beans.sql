-- ================================================================
-- Bright on Beans Coffee House Database Export
-- Generated on 2026-08-31 18:01:12
-- ================================================================

CREATE DATABASE IF NOT EXISTS `bright_on_beans` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `bright_on_beans`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'staff',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` VALUES
('1', 'admin', '$2y$10$xObdIydqt8nTZQdhLj6pwenlMTDplQiv7PSASbgr8Bl24hH0Efun.', 'Admin', 'User', 'admin', 'active', '2026-08-23 05:41:22', '2026-08-31 18:01:12'),
('2', 'ivan123', '$2y$10$xObdIydqt8nTZQdhLj6pwenlMTDplQiv7PSASbgr8Bl24hH0Efun.', 'ivan', 'pogi', 'staff', 'active', '2026-08-25 20:47:10', '2026-08-31 18:01:12'),
('3', 'Fritzie', '$2y$10$xObdIydqt8nTZQdhLj6pwenlMTDplQiv7PSASbgr8Bl24hH0Efun.', 'Fritzie', 'Suening', 'admin', 'active', '2026-08-25 23:02:27', '2026-08-31 18:01:12'),
('4', 'ivan', '$2y$10$xObdIydqt8nTZQdhLj6pwenlMTDplQiv7PSASbgr8Bl24hH0Efun.', 'ivan', 'pogi', 'staff', 'active', '2026-08-27 00:37:28', '2026-08-31 18:01:12'),
('5', 'manager', 'Manager123', 'ivan', 'pogi', 'admin', 'active', '2026-08-27 00:37:28', '2026-08-31 18:01:12');

DROP TABLE IF EXISTS `secret_questions`;
CREATE TABLE `secret_questions` (
  `sq_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `question_number` tinyint(4) NOT NULL,
  `question` varchar(255) NOT NULL,
  `answer` varchar(255) NOT NULL,
  PRIMARY KEY (`sq_id`),
  UNIQUE KEY `unique_user_question` (`user_id`,`question_number`),
  CONSTRAINT `secret_questions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `secret_questions` VALUES
('1', '2', '1', 'What is your nickname?', '$2y$10$psCp7q2yJ3/opKcpXw1prOaFHgOVq.FVRGsPjJigfsiJ1LI3vmp6u'),
('2', '2', '2', 'What is your favorite color?', '$2y$10$zWZ.652bbAmOe1xN.vv.PeiLTWKJKUd0uTh3BzsVY9boEkF5XUjEu'),
('3', '2', '3', 'What is your favorite food?', '$2y$10$ZY2NDABP63iclU5cx4h/K.sdTV3HnB37BhgIjZOJtTCnrVyIULIii'),
('4', '2', '4', 'What is the name of your best friend?', '$2y$10$3I5qjLx5Z85Fwe3vUqkuSOBdDZEjh2Osg2bavVENQ4HuEKt5FVRmC'),
('5', '2', '5', 'What is your birth month?', '$2y$10$H6P4YKTJ0/xmubaeRij0jO9P0EV.rNJSDUiJJ6oc.B32Ct5.9WT/u'),
('6', '4', '1', 'What is your nickname?', '$2y$10$SNcCxGHwFg9nrGDEzDdtbefpX2pNb.hvbTYST4iedOG52pEG/xMK2'),
('7', '4', '2', 'What is your favorite color?', '$2y$10$4T635kI46DS4g2HlF6Nji.AW8iILB9AL/zZbs3y7NQ46fKVf.D7cC'),
('8', '4', '3', 'What is your favorite food?', '$2y$10$yn45fYrreHa8PH8O90zSNebm09ugMtE/X3KAjoA09lgyJ8sTuMYeW'),
('9', '4', '4', 'What is the name of your best friend?', '$2y$10$K9wh5bgtt.IWTDX2R/U2kOUvUurDsnMD6xiyh/lr9N6IcMEsPBcZq'),
('10', '4', '5', 'What is your birth month?', '$2y$10$IkAmyokk9so5bAw9ATaFqubnZt6lz51XkypiZ5H9fItDrPIJadhzy');

DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `product_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `category` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `status` enum('available','unavailable') NOT NULL DEFAULT 'available',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`product_id`),
  KEY `idx_products_category` (`category`),
  KEY `idx_products_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sale_stock_deductions`;
DROP TABLE IF EXISTS `ingredient_stock_batches`;
DROP TABLE IF EXISTS `ingredients`;
CREATE TABLE `ingredients` (
  `ingredient_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `current_stock` decimal(12,3) NOT NULL DEFAULT 0.000,
  `minimum_level` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `date_added` date DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`ingredient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ingredient_stock_batches` (
  `batch_id` int(11) NOT NULL AUTO_INCREMENT,
  `ingredient_id` int(11) NOT NULL,
  `pallet_id` int(11) DEFAULT NULL,
  `remaining_quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `expiration_date` date DEFAULT NULL,
  `unit_cost` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`batch_id`),
  KEY `idx_active_batches_ingredient` (`ingredient_id`),
  KEY `idx_active_batches_expiration` (`expiration_date`),
  CONSTRAINT `active_batches_ingredient_fk` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `recipe_ingredients`;
CREATE TABLE `recipe_ingredients` (
  `recipe_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `quantity_per_serving` decimal(10,3) NOT NULL,
  `unit` varchar(50) NOT NULL,
  PRIMARY KEY (`recipe_id`),
  UNIQUE KEY `unique_product_ingredient` (`product_id`,`ingredient_id`),
  KEY `ingredient_id` (`ingredient_id`),
  CONSTRAINT `recipe_ingredients_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `recipe_ingredients_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sales_transactions`;
CREATE TABLE `sales_transactions` (
  `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `transaction_date` datetime NOT NULL DEFAULT current_timestamp(),
  `total_amount` decimal(12,2) NOT NULL,
  `total_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) NOT NULL DEFAULT 'cash',
  `amount_tendered` decimal(12,2) DEFAULT NULL,
  `change_amount` decimal(12,2) DEFAULT 0.00,
  `payment_reference` varchar(100) DEFAULT NULL,
  `status` enum('completed','voided') NOT NULL DEFAULT 'completed',
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `refund_method` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`transaction_id`),
  KEY `idx_sales_date` (`transaction_date`),
  KEY `idx_sales_user` (`user_id`),
  KEY `idx_sales_status` (`status`),
  CONSTRAINT `sales_transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sales_items`;
CREATE TABLE `sales_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `voided_quantity` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  PRIMARY KEY (`item_id`),
  KEY `product_id` (`product_id`),
  KEY `idx_items_transaction` (`transaction_id`),
  CONSTRAINT `sales_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `sales_transactions` (`transaction_id`) ON DELETE CASCADE,
  CONSTRAINT `sales_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sale_stock_deductions` (
  `deduction_id` int(11) NOT NULL AUTO_INCREMENT,
  `sales_item_id` int(11) NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  PRIMARY KEY (`deduction_id`),
  KEY `idx_sale_deductions_item` (`sales_item_id`),
  KEY `idx_sale_deductions_batch` (`batch_id`),
  CONSTRAINT `sale_deductions_item_fk` FOREIGN KEY (`sales_item_id`) REFERENCES `sales_items` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `sale_deductions_ingredient_fk` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`),
  CONSTRAINT `sale_deductions_batch_fk` FOREIGN KEY (`batch_id`) REFERENCES `ingredient_stock_batches` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `inventory_adjustments`;
CREATE TABLE `inventory_adjustments` (
  `adjustment_id` int(11) NOT NULL AUTO_INCREMENT,
  `ingredient_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `adjustment_type` enum('add','deduct') NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `balance_after` decimal(12,3) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`adjustment_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_adjustments_ingredient` (`ingredient_id`),
  KEY `idx_adjustments_date` (`created_at`),
  CONSTRAINT `inventory_adjustments_ibfk_1` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`),
  CONSTRAINT `inventory_adjustments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `inventory_history`;
CREATE TABLE `inventory_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `ingredient_id` int(11) NOT NULL,
  `stock_before` decimal(12,3) NOT NULL,
  `stock_after` decimal(12,3) NOT NULL,
  `change_quantity` decimal(12,3) NOT NULL,
  `change_type` enum('sale_deduction','adjustment_add','adjustment_deduct','pallet_in','pallet_out') NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `idx_history_ingredient` (`ingredient_id`),
  KEY `idx_history_date` (`created_at`),
  CONSTRAINT `inventory_history_ibfk_1` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`ingredient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
