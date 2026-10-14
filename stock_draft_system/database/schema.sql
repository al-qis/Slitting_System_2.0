-- =================================================================
-- Isolated Schema for Stock Draft System
-- Location: slitting_system_2.0/stock_draft_system/database/schema.sql
-- =================================================================

CREATE TABLE IF NOT EXISTS `draft_coils` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `source_coil_id` INT NULL COMMENT 'Reference to mother_coil or stock_raw_material ID',
  `source_type` VARCHAR(50) DEFAULT 'mother_coil' COMMENT 'mother_coil or stock_raw_material',
  `coil_no` VARCHAR(100) NOT NULL,
  `lot_no` VARCHAR(100) NOT NULL,
  `product` VARCHAR(100) NOT NULL,
  `grade` VARCHAR(50) DEFAULT NULL,
  `width` DECIMAL(10,2) DEFAULT 0.00,
  `length` DECIMAL(10,2) DEFAULT 0.00,
  `weight` DECIMAL(10,2) DEFAULT 0.00,
  `area` DECIMAL(10,2) DEFAULT 0.00,
  `roll` VARCHAR(50) DEFAULT '1',
  `customer_name` VARCHAR(150) DEFAULT 'STOCK',
  `ref_no` VARCHAR(150) DEFAULT '-',
  `state` VARCHAR(50) DEFAULT 'STOCK',
  `status` ENUM('AVAILABLE', 'RESERVED', 'USED', 'OUTGOING', 'CANCELLED') NOT NULL DEFAULT 'AVAILABLE',
  `is_on_hold` TINYINT(1) NOT NULL DEFAULT 0,
  `hold_note` VARCHAR(255) DEFAULT NULL,
  `hold_draft_customer` VARCHAR(150) DEFAULT NULL,
  `hold_draft_sos` VARCHAR(100) DEFAULT NULL,
  `hold_set_by` VARCHAR(150) DEFAULT NULL,
  `hold_set_at` DATETIME DEFAULT NULL,
  `synced_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_status` (`status`),
  KEY `idx_coil_lot` (`coil_no`, `lot_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `draft_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `draft_no` VARCHAR(50) NOT NULL UNIQUE,
  `customer_name` VARCHAR(150) NOT NULL,
  `sos_no` VARCHAR(100) NOT NULL,
  `required_length` DECIMAL(10,2) DEFAULT 0.00,
  `required_weight` DECIMAL(10,2) DEFAULT 0.00,
  `required_area` DECIMAL(10,2) DEFAULT 0.00,
  `selected_length` DECIMAL(10,2) DEFAULT 0.00,
  `selected_weight` DECIMAL(10,2) DEFAULT 0.00,
  `selected_area` DECIMAL(10,2) DEFAULT 0.00,
  `status` ENUM('DRAFT', 'RESERVED', 'CONFIRMED', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
  `created_by` VARCHAR(100) NOT NULL DEFAULT 'Production Officer',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_draft_status` (`status`),
  KEY `idx_sos_no` (`sos_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `draft_order_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `draft_order_id` INT NOT NULL,
  `draft_coil_id` INT NOT NULL,
  `allocated_length` DECIMAL(10,2) DEFAULT 0.00,
  `allocated_weight` DECIMAL(10,2) DEFAULT 0.00,
  `allocated_area` DECIMAL(10,2) DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`draft_order_id`) REFERENCES `draft_orders` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`draft_coil_id`) REFERENCES `draft_coils` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `draft_movement_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `draft_coil_id` INT NOT NULL,
  `draft_order_id` INT NULL,
  `previous_status` VARCHAR(30) NOT NULL,
  `new_status` VARCHAR(30) NOT NULL,
  `officer_id` VARCHAR(100) NOT NULL DEFAULT 'Production Officer',
  `action` VARCHAR(100) NOT NULL,
  `remarks` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_coil_log` (`draft_coil_id`),
  KEY `idx_order_log` (`draft_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
