CREATE DATABASE  IF NOT EXISTS `slitting_db` !40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci  !80016 DEFAULT ENCRYPTION='N' ;
USE `slitting_db`;
-- MySQL dump 10.13  Distrib 8.0.41, for Win64 (x86_64)
--
-- Host localhost    Database slitting_db
-- ------------------------------------------------------
-- Server version	9.2.0

!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT ;
!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS ;
!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION ;
!50503 SET NAMES utf8 ;
!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE ;
!40103 SET TIME_ZONE='+0000' ;
!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 ;
!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 ;
!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' ;
!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 ;

--
-- Table structure for table `coil_product_map`
--

DROP TABLE IF EXISTS `coil_product_map`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `coil_product_map` (
  `id` int NOT NULL AUTO_INCREMENT,
  `coil_code` varchar(10) NOT NULL,
  `product` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_coil_code` (`coil_code`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `mother_coil`
--

DROP TABLE IF EXISTS `mother_coil`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `mother_coil` (
  `id` int NOT NULL AUTO_INCREMENT,
  `coil_no` varchar(100) NOT NULL,
  `product` varchar(100) NOT NULL,
  `lot_no` varchar(100) NOT NULL,
  `grade` varchar(50) DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `length` decimal(10,2) DEFAULT NULL,
  `status` enum('NEW','IN','OUT') DEFAULT 'NEW',
  `printed_at` datetime DEFAULT NULL,
  `date_in` datetime DEFAULT NULL,
  `date_out` datetime DEFAULT NULL,
  `date_created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `in_count` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Counter for IN scans',
  `out_count` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Counter for OUT scans',
  `stock` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Stock status, IN - OUT',
  `scan_in_count` int DEFAULT '0' COMMENT 'Total times scanned IN',
  `scan_out_count` int DEFAULT '0' COMMENT 'Total times scanned OUT',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_coil_lot` (`coil_no`,`lot_no`),
  KEY `idx_stock_status` (`stock`)
) ENGINE=InnoDB AUTO_INCREMENT=602 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `mother_coil_audit_log`
--

DROP TABLE IF EXISTS `mother_coil_audit_log`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `mother_coil_audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mother_id` int NOT NULL,
  `action_type` enum('IN','OUT','SCAN_IN','SCAN_OUT','CREATED','UPDATED') NOT NULL,
  `performed_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `remark` text,
  PRIMARY KEY (`id`),
  KEY `fk_audit_mother` (`mother_id`),
  KEY `idx_performed_at` (`performed_at`),
  CONSTRAINT `fk_audit_mother` FOREIGN KEY (`mother_id`) REFERENCES `mother_coil` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=149 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `nci_product_mapping`
--

DROP TABLE IF EXISTS `nci_product_mapping`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `nci_product_mapping` (
  `id` int NOT NULL AUTO_INCREMENT,
  `internal_code` varchar(50) DEFAULT NULL,
  `product` varchar(100) DEFAULT NULL,
  `width` varchar(50) DEFAULT NULL,
  `customer` varchar(100) DEFAULT NULL,
  `part_no` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `pallet_delivery_log`
--

DROP TABLE IF EXISTS `pallet_delivery_log`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `pallet_delivery_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pallet_id` int unsigned NOT NULL,
  `pallet_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rolls_delivered` tinyint unsigned NOT NULL,
  `triggered_by_product_id` int unsigned DEFAULT NULL,
  `performed_by` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'slitting',
  `delivered_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pdl_pallet` (`pallet_id`),
  KEY `idx_pdl_date` (`delivered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `pallet_edit_log`
--

DROP TABLE IF EXISTS `pallet_edit_log`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `pallet_edit_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pallet_id` int unsigned NOT NULL,
  `pallet_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` int unsigned DEFAULT NULL COMMENT 'The slitting_product row affected (NULL for pallet-level actions)',
  `product_ref` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Snapshot of lot+coil+roll at time of edit',
  `performed_by` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'slitting',
  `performed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pel_pallet` (`pallet_id`),
  KEY `idx_pel_date` (`performed_at`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `pallet_items`
--

DROP TABLE IF EXISTS `pallet_items`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `pallet_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `stock_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pallet_id` int unsigned NOT NULL,
  `slitting_product_id` int unsigned NOT NULL,
  `seq` tinyint unsigned NOT NULL,
  `added_by` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'slitting',
  `winding_condition` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = Winding Condition checkbox was ticked by QC inspector',
  `hairy_rubber` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = Hairy Rubber checkbox was ticked by QC inspector',
  `qc_checked_at` datetime DEFAULT NULL COMMENT 'Timestamp when this row was marked as the top inspected roll',
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_on_pallet` (`slitting_product_id`),
  KEY `idx_pallet_items_pallet` (`pallet_id`),
  KEY `idx_checklist` (`pallet_id`,`winding_condition`,`hairy_rubber`),
  CONSTRAINT `fk_pi_pallet` FOREIGN KEY (`pallet_id`) REFERENCES `pallets` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=295 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `pallets`
--

DROP TABLE IF EXISTS `pallets`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `pallets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pallet_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `width` decimal(8,2) NOT NULL,
  `status` enum('building','pending_qc','approved','rejected','delivered') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'building',
  `qc_comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `checked_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Inspector name selected from qc_inspectors during QC action',
  `rejected_at` datetime DEFAULT NULL COMMENT 'Timestamp of the most recent QC rejection',
  `edit_count` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'Number of times this pallet was edited after QC rejection',
  `created_by` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'slitting',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delivered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pallet_no` (`pallet_no`),
  KEY `idx_pallets_status` (`status`),
  KEY `idx_pallets_customer` (`customer_name`),
  KEY `idx_pallets_delivered` (`delivered_at`),
  KEY `idx_pallets_rejected_at` (`rejected_at`),
  KEY `idx_checked_by` (`checked_by`)
) ENGINE=InnoDB AUTO_INCREMENT=118 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `process_log`
--

DROP TABLE IF EXISTS `process_log`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `process_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `entity_type` enum('slitting','recoiling','reslit','sfc','stock','mother') NOT NULL COMMENT 'Which table this log entry relates to',
  `entity_id` int NOT NULL COMMENT 'The PK of the related row in that table',
  `mother_id` int DEFAULT NULL COMMENT 'Denormalized for fast mother-coil reporting',
  `from_status` varchar(50) DEFAULT NULL COMMENT 'Status before the change',
  `to_status` varchar(50) NOT NULL COMMENT 'Status after the change',
  `performed_by` varchar(100) DEFAULT NULL COMMENT 'Username or role who triggered this action',
  `performed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `action_detail` varchar(255) DEFAULT NULL COMMENT 'Extra context e.g. send_to_reslit, qc_approve',
  `remark` text,
  PRIMARY KEY (`id`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_mother` (`mother_id`),
  KEY `idx_performed_at` (`performed_at`),
  KEY `idx_to_status` (`to_status`),
  CONSTRAINT `fk_process_log_mother` FOREIGN KEY (`mother_id`) REFERENCES `mother_coil` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=609 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Immutable audit log — one row per status change across all processes';
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `qc_inspectors`
--

DROP TABLE IF EXISTS `qc_inspectors`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `qc_inspectors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = visible in dropdown, 0 = soft-deleted',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspector_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Predefined list of QC inspectors for the Checked By dropdown';
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `raw_material_log`
--

DROP TABLE IF EXISTS `raw_material_log`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `raw_material_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mother_id` int DEFAULT NULL,
  `status` enum('IN','OUT') NOT NULL,
  `action` varchar(50) DEFAULT NULL COMMENT 'normal or cut_into_2',
  `date_in` datetime DEFAULT NULL,
  `date_out` datetime DEFAULT NULL,
  `remark` text,
  `length` decimal(10,2) DEFAULT NULL COMMENT 'Length used in this action',
  `width` decimal(10,2) DEFAULT NULL COMMENT 'Width of material',
  PRIMARY KEY (`id`),
  KEY `fk_log_to_mother` (`mother_id`),
  CONSTRAINT `fk_log_to_mother` FOREIGN KEY (`mother_id`) REFERENCES `mother_coil` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `recoiling_product`
--

DROP TABLE IF EXISTS `recoiling_product`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `recoiling_product` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slitting_product_id` int DEFAULT NULL COMMENT 'FK → slitting_product.id — the exact roll sent to recoiling',
  `mother_id` int DEFAULT NULL,
  `status` enum('pending','completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'pending',
  `source` varchar(50) DEFAULT NULL COMMENT 'Immediate source',
  `product` varchar(100) NOT NULL,
  `lot_no` varchar(100) NOT NULL,
  `coil_no` varchar(100) NOT NULL,
  `roll_no` varchar(100) DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `new_width` decimal(10,2) DEFAULT NULL,
  `length` decimal(10,2) DEFAULT NULL,
  `new_length` decimal(10,2) DEFAULT NULL,
  `actual_length` decimal(10,2) DEFAULT NULL,
  `date_in` datetime DEFAULT CURRENT_TIMESTAMP,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `cut_type` varchar(50) DEFAULT NULL,
  `remark` text,
  `original_source` varchar(50) DEFAULT 'raw_material' COMMENT 'Permanent original source (raw_material, sfc)',
  PRIMARY KEY (`id`),
  KEY `fk_recoiling_mother_idx` (`mother_id`),
  KEY `idx_recoiling_original_source` (`original_source`),
  KEY `fk_recoiling_from_slitting` (`slitting_product_id`),
  CONSTRAINT `fk_recoiling_from_slitting` FOREIGN KEY (`slitting_product_id`) REFERENCES `slitting_product` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_recoiling_mother` FOREIGN KEY (`mother_id`) REFERENCES `mother_coil` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `reslit_product`
--

DROP TABLE IF EXISTS `reslit_product`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `reslit_product` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mother_id` int DEFAULT NULL COMMENT 'FK to mother_coil — NULL for SFC-origin records',
  `slitting_product_id` int DEFAULT NULL COMMENT 'FK → slitting_product.id — the exact roll that was sent to reslit',
  `source_sfc_id` int DEFAULT NULL,
  `status` enum('pending','in_progress','completed') DEFAULT 'pending',
  `source` varchar(50) DEFAULT NULL COMMENT 'Immediate source',
  `product` varchar(100) DEFAULT NULL,
  `lot_no` varchar(100) DEFAULT NULL,
  `coil_no` varchar(100) DEFAULT NULL,
  `roll_no` varchar(100) DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `new_width` decimal(10,2) DEFAULT NULL,
  `length` decimal(10,2) DEFAULT NULL,
  `date_in` datetime DEFAULT CURRENT_TIMESTAMP,
  `qr_code` varchar(255) DEFAULT NULL,
  `cut_type` varchar(50) DEFAULT NULL,
  `original_source` varchar(50) DEFAULT 'raw_material' COMMENT 'Permanent original source (raw_material, sfc)',
  `actual_length` decimal(10,2) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `date_reslit` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reslit_original_source` (`original_source`),
  KEY `fk_reslit_from_slitting` (`slitting_product_id`),
  KEY `fk_reslit_mother_idx` (`mother_id`),
  KEY `idx_reslit_source_sfc` (`source_sfc_id`),
  CONSTRAINT `fk_reslit_from_slitting` FOREIGN KEY (`slitting_product_id`) REFERENCES `slitting_product` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_reslit_mother` FOREIGN KEY (`mother_id`) REFERENCES `mother_coil` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `reslit_rolls`
--

DROP TABLE IF EXISTS `reslit_rolls`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `reslit_rolls` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parent_id` int NOT NULL,
  `roll_no` varchar(50) NOT NULL,
  `cut_letter` varchar(10) DEFAULT NULL,
  `new_width` decimal(10,2) DEFAULT NULL,
  `length` decimal(10,2) DEFAULT NULL,
  `actual_length` decimal(10,2) DEFAULT NULL,
  `status` enum('pending','in_progress','completed') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `original_source` varchar(50) DEFAULT 'raw_material',
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `fk_reslit_parent` FOREIGN KEY (`parent_id`) REFERENCES `reslit_product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_reslit_rolls_parent` FOREIGN KEY (`parent_id`) REFERENCES `reslit_product` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `sfc`
--

DROP TABLE IF EXISTS `sfc`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `sfc` (
  `sfc_id` int NOT NULL AUTO_INCREMENT,
  `mother_id` int DEFAULT NULL COMMENT 'FK to mother_coil — NULL for SFC-origin records',
  `product` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `lot_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coil_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `roll_no` varchar(50) DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `length` decimal(10,2) DEFAULT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(50) DEFAULT NULL,
  `date_created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `date_out` datetime DEFAULT NULL,
  PRIMARY KEY (`sfc_id`),
  KEY `fk_sfc_mother_idx` (`mother_id`),
  KEY `idx_sfc_is_deleted` (`is_deleted`),
  CONSTRAINT `fk_sfc_mother` FOREIGN KEY (`mother_id`) REFERENCES `mother_coil` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `slitting_audit_log`
--

DROP TABLE IF EXISTS `slitting_audit_log`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `slitting_audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mother_id` int NOT NULL,
  `action` varchar(50) NOT NULL COMMENT 'send_to_sfc, send_to_finished, etc',
  `roll_no` varchar(20) NOT NULL,
  `destination` varchar(50) NOT NULL COMMENT 'sfc_stock, slitting_product, etc',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mother_id` (`mother_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_slitting_audit_mother` FOREIGN KEY (`mother_id`) REFERENCES `mother_coil` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=261 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `slitting_plans`
--

DROP TABLE IF EXISTS `slitting_plans`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `slitting_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mother_coil_id` int NOT NULL,
  `roll_seq` varchar(10) NOT NULL,
  `planned_width` decimal(10,2) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slitting_plans_mother` (`mother_coil_id`),
  CONSTRAINT `fk_slitting_plans_mother` FOREIGN KEY (`mother_coil_id`) REFERENCES `mother_coil` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=82 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `slitting_product`
--

DROP TABLE IF EXISTS `slitting_product`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `slitting_product` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product` varchar(100) DEFAULT NULL,
  `lot_no` varchar(100) DEFAULT NULL,
  `coil_no` varchar(50) DEFAULT NULL,
  `roll_no` varchar(100) DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `length` decimal(10,2) DEFAULT NULL,
  `actual_length` decimal(10,2) DEFAULT NULL,
  `nod_length` decimal(10,2) DEFAULT NULL,
  `nod_recorded_at` datetime DEFAULT NULL,
  `nod_recorded_by` varchar(50) DEFAULT NULL,
  `length_type` varchar(20) DEFAULT NULL,
  `status` enum('IN','WAITING','APPROVED','REJECTED','DELIVERED','OUT') DEFAULT 'IN',
  `qc_comment` text,
  `is_completed` tinyint(1) DEFAULT '0',
  `stock_counted` tinyint(1) DEFAULT '0',
  `date_in` datetime DEFAULT NULL,
  `date_out` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL COMMENT 'Customer captured at sticker print time',
  `ref_no` varchar(150) DEFAULT NULL COMMENT 'Customer ref  part number at print time',
  `is_printed` tinyint(1) NOT NULL DEFAULT '0',
  `print_count` int NOT NULL DEFAULT '0',
  `first_printed_at` datetime DEFAULT NULL,
  `last_printed_at` datetime DEFAULT NULL,
  `last_printed_by` varchar(50) DEFAULT NULL,
  `delivered_by` varchar(100) DEFAULT NULL COMMENT 'Who delivered this roll to customer',
  `mother_id` int DEFAULT NULL,
  `from_log_id` int DEFAULT NULL,
  `cut_type` varchar(20) DEFAULT 'normal' COMMENT 'normal or cut_into_2',
  `slit_quantity` decimal(10,2) DEFAULT NULL COMMENT 'Quantity untuk cut into 2',
  `stock_value` decimal(10,2) DEFAULT NULL COMMENT 'Stock amount yang return ke mother coil',
  `stock_mother_id` int DEFAULT NULL COMMENT 'Reference to new mother coil created from stock',
  `is_recoiled` tinyint(1) DEFAULT '0',
  `recoil_reason` varchar(255) DEFAULT NULL,
  `is_reslitted` tinyint(1) DEFAULT '0',
  `reslit_reason` varchar(255) DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `cut_reason` varchar(255) DEFAULT NULL,
  `leftover_length` decimal(10,2) DEFAULT NULL COMMENT 'Leftover length (meters) saved back to stock after Cut Into 2',
  `std_weight` decimal(10,4) DEFAULT '0.0000' COMMENT 'Standard weight for calculation',
  `recoiling_id` int DEFAULT NULL,
  `parent_slit_id` int DEFAULT NULL COMMENT 'Self-ref FK if this roll came from recoilingreslitting another roll',
  `source` varchar(50) NOT NULL DEFAULT 'raw_material',
  `original_source` varchar(50) DEFAULT 'raw_material' COMMENT 'Permanent original source (raw_material, sfc)',
  `is_voided` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = soft-deleted, hidden from all active views',
  `voided_at` datetime DEFAULT NULL COMMENT 'Timestamp when the row was voided',
  `voided_reason` varchar(255) DEFAULT NULL COMMENT 'Reason for voiding manual_delete, duplicate, etc.',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `roll_key` varchar(400) GENERATED ALWAYS AS ((case when (coalesce(`is_voided`,0) = 0) then concat(`lot_no`,_utf8mb4'',`coil_no`,_utf8mb4'',`roll_no`) else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_production_roll` (`roll_key`),
  KEY `idx_recoiling_id` (`recoiling_id`),
  KEY `fk_slitting_log` (`from_log_id`),
  KEY `idx_original_source` (`original_source`),
  KEY `fk_slit_parent` (`parent_slit_id`),
  KEY `fk_slitting_std_wgt` (`product`),
  KEY `idx_is_voided` (`is_voided`),
  KEY `fk_slitting_mother` (`mother_id`),
  KEY `idx_customer_name` (`customer_name`),
  KEY `idx_slitting_product_source` (`source`),
  KEY `idx_is_printed` (`is_printed`),
  KEY `idx_nod_recorded_at` (`nod_recorded_at`),
  CONSTRAINT `fk_slit_parent` FOREIGN KEY (`parent_slit_id`) REFERENCES `slitting_product` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_slitting_log` FOREIGN KEY (`from_log_id`) REFERENCES `raw_material_log` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_slitting_mother` FOREIGN KEY (`mother_id`) REFERENCES `mother_coil` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_slitting_std_wgt` FOREIGN KEY (`product`) REFERENCES `std_wgt` (`product_code`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2798 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `source_tracking_log`
--

DROP TABLE IF EXISTS `source_tracking_log`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `source_tracking_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `table_name` varchar(50) NOT NULL COMMENT 'slitting_product, recoiling_product, reslit_product',
  `original_source` varchar(50) NOT NULL COMMENT 'Permanent source (raw_material, sfc)',
  `current_source` varchar(50) NOT NULL COMMENT 'Current destination',
  `action` varchar(100) DEFAULT NULL COMMENT 'send_to_recoiling, send_to_reslit, etc',
  `tracked_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_table_source` (`table_name`,`original_source`),
  KEY `idx_tracked_at` (`tracked_at`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Permanent source tracking for products through their lifecycle';
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `std_wgt`
--

DROP TABLE IF EXISTS `std_wgt`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `std_wgt` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_code` varchar(100) NOT NULL,
  `std_weight` decimal(10,4) NOT NULL COMMENT 'Standard weight for calculation',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_product` (`product_code`),
  KEY `idx_product` (`product_code`)
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Standard weight lookup table';
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `stock_crosscheck_scans`
--

DROP TABLE IF EXISTS `stock_crosscheck_scans`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `stock_crosscheck_scans` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `raw` varchar(255) NOT NULL,
  `lot` varchar(20) NOT NULL,
  `coil` varchar(50) NOT NULL,
  `roll` varchar(20) NOT NULL,
  `width` varchar(20) NOT NULL,
  `length` varchar(20) NOT NULL,
  `product_code` varchar(100) NOT NULL,
  `d365_item_number` varchar(150) NOT NULL,
  `d365_lot_no` varchar(150) NOT NULL,
  `mtr` varchar(20) NOT NULL,
  `scanned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_raw` (`raw`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `stock_raw_material`
--

DROP TABLE IF EXISTS `stock_raw_material`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `stock_raw_material` (
  `id` int NOT NULL AUTO_INCREMENT,
  `length` decimal(10,2) DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `lot_no` varchar(100) DEFAULT NULL,
  `grade` varchar(50) DEFAULT NULL,
  `coil_no` varchar(100) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `source_type` varchar(50) DEFAULT NULL,
  `source_id` int DEFAULT NULL,
  `date_in` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_mother_source` (`source_id`),
  CONSTRAINT `fk_mother_source` FOREIGN KEY (`source_id`) REFERENCES `mother_coil` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;

--
-- Table structure for table `waiting_approval`
--

DROP TABLE IF EXISTS `waiting_approval`;
!40101 SET @saved_cs_client     = @@character_set_client ;
!50503 SET character_set_client = utf8mb4 ;
CREATE TABLE `waiting_approval` (
  `id` int NOT NULL AUTO_INCREMENT,
  `finish_id` int NOT NULL,
  `status` enum('PENDING','APPROVED','DELIVERED') NOT NULL DEFAULT 'PENDING',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_approval_slitting` (`finish_id`),
  CONSTRAINT `fk_approval_slitting` FOREIGN KEY (`finish_id`) REFERENCES `slitting_product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
!40101 SET character_set_client = @saved_cs_client ;
!40103 SET TIME_ZONE=@OLD_TIME_ZONE ;

!40101 SET SQL_MODE=@OLD_SQL_MODE ;
!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS ;
!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS ;
!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT ;
!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS ;
!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION ;
!40111 SET SQL_NOTES=@OLD_SQL_NOTES ;

-- Dump completed on 2026-08-05  93652

-- MySQL dump 10.13  Distrib 8.0.41, for Win64 (x86_64)
--
-- Host localhost    Database slitting_db
-- ------------------------------------------------------
-- Server version	9.2.0

!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT ;
!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS ;
!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION ;
!50503 SET NAMES utf8 ;
!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE ;
!40103 SET TIME_ZONE='+0000' ;
!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 ;
!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 ;
!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' ;
!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 ;

--
-- Dumping data for table `coil_product_map`
--

LOCK TABLES `coil_product_map` WRITE;
!40000 ALTER TABLE `coil_product_map` DISABLE KEYS ;
INSERT INTO `coil_product_map` VALUES (1,'A','RS-3825'),(3,'B','RS-4525'),(4,'BP','RS-3825-04'),(5,'CG','DS-3020'),(6,'CH','DS-3825'),(7,'CI','DS-4525'),(8,'CJ','DS-5030'),(9,'CM','DS-8460'),(10,'EC','LN-2520-04'),(11,'ED','L1N2-2520-02'),(12,'FJ','LZ-2420'),(13,'FK','LN-2520'),(17,'FN','YW-2520-SG'),(18,'FR','LN-1715-1'),(19,'FR','LN-1715-838'),(20,'FR','LN-2520-838'),(21,'FV','LZ-2520'),(22,'FV','LZ-2520-788'),(23,'G','RS-4020'),(24,'H','RS-5030'),(25,'HPM','HBV-4020'),(26,'HPM','MV-4020'),(27,'J','RS-6040'),(28,'HCM','DS-8460'),(30,'HQA','JZ-2520'),(31,'HQE','JZ-3020'),(32,'K','RS-7050'),(33,'LA','TS-5030'),(34,'LG','RS-4025'),(35,'LG','RS-4525'),(36,'LG','TS-4025'),(37,'LI','TS-9080-SG'),(38,'LJ','TS-9080'),(39,'LM','TS-2620'),(40,'LQ','TS-3525-SG'),(41,'N','TU-3020'),(42,'O','JV-3825'),(43,'O','RV-3825'),(44,'P','TS-3525'),(45,'P6','PS-6020'),(46,'PS','PS-8525'),(47,'QA','JZ-2520'),(48,'QA','JZ-2520-2C'),(50,'QB','JZ-4020'),(51,'QE','JZ-3020'),(52,'QM','JZ-2820'),(54,'RA','RU-5040-1'),(55,'RG','RB-6440'),(56,'RH','GB-6440-S101'),(57,'RK','KB-6440'),(58,'RL','GB-7640'),(59,'RN','RB-5040-2'),(60,'RR','GB-6440'),(61,'RU','RU-5040-1-S101'),(62,'TG','TU-4020'),(63,'V','RS-3020'),(65,'Z','TU-2620'),(66,'ZC','TU-2620-C');
!40000 ALTER TABLE `coil_product_map` ENABLE KEYS ;
UNLOCK TABLES;

--
-- Dumping data for table `nci_product_mapping`
--

LOCK TABLES `nci_product_mapping` WRITE;
!40000 ALTER TABLE `nci_product_mapping` DISABLE KEYS ;
INSERT INTO `nci_product_mapping` VALUES (1,'A-115','RS-3825','115 mm','DELPHI (Mexico)','65720506572051','2026-01-22 072023'),(2,'A-120','RS-3825','120 mm','DELPHI (Brazil)','06571926  06571927  06572176','2026-01-22 072023'),(3,'G-125','RS-4020','125 mm','DELPHI (Mexico)','06571982','2026-01-22 072023'),(4,'KB-101','KB-6440','101 mm','AMBRAKE','51-A3826-67434','2026-01-22 072023'),(5,'KB-111','KB-6440','111 mm','AMBRAKE','AB-A4315-67430','2026-01-22 072023'),(6,'KB-113','KB-6440','113 mm','ADVICS','115-5314','2026-01-22 072023'),(7,'KB-136','KB-6440','136 mm','ADVICS','115-5704','2026-01-22 072023'),(8,'KB-137','KB-6440','137 mm','ADVICS','115-5704','2026-01-22 072023'),(9,'KB-141','KB-6440','141 mm','ADVICS','115-5315','2026-01-22 072023'),(10,'KB-155','KB-6440','155 mm','AMBRAKE','51-E4532-57431','2026-01-22 072023'),(11,'KB-167','KB-6440','167 mm','AMAK  AMBRAKE','51-E5112-57431  AB-E5111-57431','2026-01-22 072023'),(12,'KB-210','KB-6440','210 mm','AMAK','51-A5739-57430','2026-01-22 072023'),(13,'N-313','TU-3020','313 mm','TOYOTA','1717717178-0P020','2026-01-22 072023'),(14,'P-154','TS-3525','154 mm','AAC','213231-12090 (Plate Gasket)','2026-01-22 072023'),(15,'P-89','TS-3525','89 mm','TOYOTA','15147-0P020','2026-01-22 072023'),(16,'TG-313','TU-4020','313 mm','AAC','213231-12080 (WPG MK)','2026-01-22 072023');
!40000 ALTER TABLE `nci_product_mapping` ENABLE KEYS ;
UNLOCK TABLES;

--
-- Dumping data for table `std_wgt`
--

LOCK TABLES `std_wgt` WRITE;
!40000 ALTER TABLE `std_wgt` DISABLE KEYS ;
INSERT INTO `std_wgt` VALUES (1,'DS-3020',1.7300),(2,'DS-3825',2.1690),(3,'DS-4525',2.2600),(4,'DS-5030',2.6600),(5,'DS-8460',5.1100),(6,'GB-6440',3.5100),(7,'GB-6440-S101',3.5100),(8,'GB-7640',3.6700),(9,'HBV-4020',1.7300),(10,'JV-3825',2.1690),(11,'JZ-2520',1.6700),(12,'JZ-2520-2C',1.6700),(13,'JZ-2520-2C-788',1.6700),(14,'JZ-2820',1.7000),(15,'JZ-2820-788',1.7000),(16,'JZ-3020',1.7300),(17,'JZ-4020',1.8600),(18,'KB-6440',3.5120),(19,'L1N2-2520-02',1.6700),(20,'LN-1715-1',1.5600),(21,'LN-1715-838',1.6700),(22,'LN-2520',1.6700),(23,'LN-2520-02',1.6700),(24,'LN-2520-04',1.6700),(25,'LN-2520-788',1.6700),(26,'LN-2520-838',1.6700),(27,'LN-2520-936',1.6700),(28,'LN-2520-1025',1.6700),(29,'LZ-2420',1.6100),(30,'LZ-2520',1.6700),(31,'LZ-2520-788',1.6700),(32,'MV-4020',1.7300),(33,'PS-6020',1.9100),(34,'PS-8525',2.2400),(35,'RB-5040-2',3.5100),(36,'RB-6440',3.5120),(37,'RS-3020',1.7300),(38,'RS-3825',2.1690),(39,'RS-3825-04',2.1700),(40,'RS-4020',1.8600),(41,'RS-4025',2.1900),(42,'RS-4525',2.2600),(43,'RS-5030',2.6600),(44,'RS-6040',3.4600),(45,'RS-7050',4.2600),(46,'RU-5040-1',3.3300),(47,'RU-5040-1-S101',3.3300),(48,'RV-3825',2.1690),(49,'TS-2620',1.6780),(50,'TS-3020',1.7300),(51,'TS-3525',2.1300),(52,'TS-3525-SG',2.1300),(53,'TS-4025',2.1900),(54,'TS-4525',2.2600),(55,'TS-5030',2.6600),(56,'TS-9080',6.5300),(57,'TS-9080-SG',6.5300),(58,'TU-2620',1.6780),(59,'TU-2620-C',1.6780),(60,'TU-3020',1.7300),(61,'TU-4020',1.8600),(62,'YW-2520-SG',1.6700),(63,'RT-3520',2.1690);
!40000 ALTER TABLE `std_wgt` ENABLE KEYS ;
UNLOCK TABLES;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
!40000 ALTER TABLE `users` DISABLE KEYS ;
INSERT INTO `users` VALUES (1,'slitting','$2y$10$8utZ6odMkhIqAKYZrNS2u7q8zDYRdrXnNHhr1hzCub1b9evcF.K','slitting'),(2,'mkl3','$2y$10$8utZ6odMkhIqAKYZrNS2u7q8zDYRdrXnNHhr1hzCub1b9evcF.K','mkl3'),(3,'qc','$2y$10$8utZ6odMkhIqAKYZrNS2u7q8zDYRdrXnNHhr1hzCub1b9evcF.K','qc');
!40000 ALTER TABLE `users` ENABLE KEYS ;
UNLOCK TABLES;
!40103 SET TIME_ZONE=@OLD_TIME_ZONE ;

!40101 SET SQL_MODE=@OLD_SQL_MODE ;
!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS ;
!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS ;
!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT ;
!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS ;
!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION ;
!40111 SET SQL_NOTES=@OLD_SQL_NOTES ;

-- Dump completed on 2026-08-05  93809
