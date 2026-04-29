CREATE DATABASE  IF NOT EXISTS `slitting_db_test` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `slitting_db_test`;
-- MySQL dump 10.13  Distrib 8.0.41, for Win64 (x86_64)
--
-- Host: localhost    Database: slitting_db_test
-- ------------------------------------------------------
-- Server version	9.2.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `coil_product_map`
--

DROP TABLE IF EXISTS `coil_product_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;

CREATE TABLE `coil_product_map` (
  `id` int NOT NULL AUTO_INCREMENT,
  `coil_code` varchar(10) NOT NULL,
  `product` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `coil_code` (`coil_code`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `coil_product_map`
--

LOCK TABLES `coil_product_map` WRITE;
/*!40000 ALTER TABLE `coil_product_map` DISABLE KEYS */;
INSERT INTO `coil_product_map` VALUES (1,'A','RS-3825'),(2,'B','TS-4525'),(3,'BP','RS-3825-04'),(4,'CG','DS-3020'),(5,'CH','DS-3825'),(6,'CI','DS-4525'),(7,'CJ','DS-5030'),(8,'CM','DS-8460'),(9,'EC','LN-2520-04'),(10,'ED','L1N2-2520-02'),(11,'FK','LN-2520'),(12,'FL','LN-2420'),(13,'FN','YW-2520-SG'),(14,'FR','LN-1715-1'),(15,'FV','LZ-2520 - 788'),(16,'G','RS-4020'),(17,'H','RS-5030'),(18,'HPM','HBV-4020'),(19,'J','RS-6040'),(20,'K','RS-7050'),(21,'LA','TS-5030'),(22,'LG','RS-4025'),(23,'LQ','TS-3525-SG'),(24,'N','TU-3020'),(25,'P','TS-3525'),(26,'P6','PS-6020'),(27,'PM','MV-4020'),(28,'PS','PS-8525'),(29,'QA','JZ-2520-2C'),(30,'QB','JZ-4020'),(31,'QE','JZ-3020'),(32,'QM','JZ-2820'),(33,'RA','RU-5040-1'),(34,'RG','RB-6440'),(35,'RH','GB-6440-05'),(36,'RK','KB-6440'),(37,'RL','GB-7640'),(38,'RN','RB-5040-2'),(39,'RR','GB-6440'),(40,'RU','RU-5040-1-S101'),(41,'TG','TU-4020'),(42,'V','RS-3020'),(43,'Z','TU-2620'),(44,'JCM','DS-8460'),(45,'JPM','MV-4020'),(46,'JQA','JZ-2520'),(47,'JQE','JZ-3020');
/*!40000 ALTER TABLE `coil_product_map` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mother_coil`
--

DROP TABLE IF EXISTS `mother_coil`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mother_coil` (
  `id` int NOT NULL AUTO_INCREMENT,
  `coil_no` varchar(100) NOT NULL,
  `product` varchar(100) NOT NULL,
  `lot_no` varchar(100) NOT NULL,
  `grade` varchar(50) DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `length` decimal(10,2) DEFAULT NULL,
  `status` enum('NEW','IN','OUT') DEFAULT 'NEW',
  `date_in` datetime DEFAULT NULL,
  `date_out` datetime DEFAULT NULL,
  `date_created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `in_count` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Counter for IN scans',
  `out_count` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Counter for OUT scans',
  `stock` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Stock status, IN - OUT',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_coil_lot` (`coil_no`,`lot_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mother_coil`
--

LOCK TABLES `mother_coil` WRITE;
/*!40000 ALTER TABLE `mother_coil` DISABLE KEYS */;
/*!40000 ALTER TABLE `mother_coil` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mother_coil_audit_log`
--

DROP TABLE IF EXISTS `mother_coil_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mother_coil_audit_log`
--

LOCK TABLES `mother_coil_audit_log` WRITE;
/*!40000 ALTER TABLE `mother_coil_audit_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `mother_coil_audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nci_product_mapping`
--

DROP TABLE IF EXISTS `nci_product_mapping`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nci_product_mapping`
--

LOCK TABLES `nci_product_mapping` WRITE;
/*!40000 ALTER TABLE `nci_product_mapping` DISABLE KEYS */;
INSERT INTO `nci_product_mapping` VALUES (1,'A-115','RS-3825','115 mm','DELPHI (Mexico)','6572050/6572051','2026-01-22 07:20:23'),(2,'A-120','RS-3825','120 mm','DELPHI (Brazil)','06571928 / 06571927 / 06572176','2026-01-22 07:20:23'),(3,'G-125','RS-4020','125 mm','DELPHI (Mexico)','06571982','2026-01-22 07:20:23'),(4,'KB-101','KB-6440','101 mm','AMBRAKE','51-A3826-67434','2026-01-22 07:20:23'),(5,'KB-111','KB-6440','111 mm','AMBRAKE','AB-A4315-67430','2026-01-22 07:20:23'),(6,'KB-113','KB-6440','113 mm','ADVICS','115-5314','2026-01-22 07:20:23'),(7,'KB-136','KB-6440','136 mm','ADVICS','115-5704','2026-01-22 07:20:23'),(8,'KB-137','KB-6440','137 mm','ADVICS','115-5704','2026-01-22 07:20:23'),(9,'KB-141','KB-6440','141 mm','ADVICS','115-5315','2026-01-22 07:20:23'),(10,'KB-155','KB-6440','155 mm','AMBRAKE','51-E4532-57431','2026-01-22 07:20:23'),(11,'KB-167','KB-6440','167 mm','AMAK / AMBRAKE','51-E5112-57431 / AB-E5111-57431','2026-01-22 07:20:23'),(12,'KB-210','KB-6440','210 mm','AMAK','51-A5739-57430','2026-01-22 07:20:23'),(13,'N-313','TU-3020','313 mm','TOYOTA','17177/17178-0P020','2026-01-22 07:20:23'),(14,'P-154','TS-3525','154 mm','AAC','213231-12090 (Plate Gasket)','2026-01-22 07:20:23'),(15,'P-89','TS-3525','89 mm','TOYOTA','15147-0P020','2026-01-22 07:20:23'),(16,'TG-313','TU-4020','313 mm','AAC','213231-12080 (WPG MK)','2026-01-22 07:20:23');
/*!40000 ALTER TABLE `nci_product_mapping` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary view structure for view `production_yield_summary`
--

DROP TABLE IF EXISTS `production_yield_summary`;
/*!50001 DROP VIEW IF EXISTS `production_yield_summary`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `production_yield_summary` AS SELECT 
 1 AS `mother_id`,
 1 AS `coil_no`,
 1 AS `product`,
 1 AS `input_width`,
 1 AS `output_width`,
 1 AS `waste_width`,
 1 AS `yield_percentage`,
 1 AS `date_created`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `raw_material_log`
--

DROP TABLE IF EXISTS `raw_material_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `raw_material_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mother_id` int DEFAULT NULL,
  `status` enum('IN','OUT') NOT NULL,
  `action` varchar(50) DEFAULT NULL COMMENT 'normal or cut_into_2',
  `date_in` datetime DEFAULT NULL,
  `date_out` datetime DEFAULT NULL,
  `remark` text,
  PRIMARY KEY (`id`),
  KEY `fk_log_to_mother` (`mother_id`),
  CONSTRAINT `fk_log_to_mother` FOREIGN KEY (`mother_id`) REFERENCES `mother_coil` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `raw_material_log`
--

LOCK TABLES `raw_material_log` WRITE;
/*!40000 ALTER TABLE `raw_material_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `raw_material_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recoiling_product`
--

DROP TABLE IF EXISTS `recoiling_product`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recoiling_product` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mother_id` int DEFAULT NULL,
  `status` enum('pending','completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'pending',
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
  PRIMARY KEY (`id`),
  KEY `fk_recoiling_mother_idx` (`mother_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recoiling_product`
--

LOCK TABLES `recoiling_product` WRITE;
/*!40000 ALTER TABLE `recoiling_product` DISABLE KEYS */;
/*!40000 ALTER TABLE `recoiling_product` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reslit_product`
--

DROP TABLE IF EXISTS `reslit_product`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reslit_product` (
  `id` int NOT NULL AUTO_INCREMENT,
  `status` enum('pending','in_progress','completed') DEFAULT 'pending',
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
  `actual_length` decimal(10,2) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `date_reslit` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reslit_product`
--

LOCK TABLES `reslit_product` WRITE;
/*!40000 ALTER TABLE `reslit_product` DISABLE KEYS */;
/*!40000 ALTER TABLE `reslit_product` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reslit_rolls`
--

DROP TABLE IF EXISTS `reslit_rolls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `fk_reslit_parent` FOREIGN KEY (`parent_id`) REFERENCES `reslit_product` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reslit_rolls`
--

LOCK TABLES `reslit_rolls` WRITE;
/*!40000 ALTER TABLE `reslit_rolls` DISABLE KEYS */;
/*!40000 ALTER TABLE `reslit_rolls` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sfc`
--

DROP TABLE IF EXISTS `sfc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sfc` (
  `sfc_id` int NOT NULL AUTO_INCREMENT,
  `product` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `lot_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `coil_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `length` decimal(10,2) DEFAULT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `date_created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `date_out` datetime DEFAULT NULL,
  PRIMARY KEY (`sfc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sfc`
--

LOCK TABLES `sfc` WRITE;
/*!40000 ALTER TABLE `sfc` DISABLE KEYS */;
/*!40000 ALTER TABLE `sfc` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `slitting_product`
--

DROP TABLE IF EXISTS `slitting_product`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `slitting_product` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product` varchar(100) DEFAULT NULL,
  `lot_no` varchar(100) DEFAULT NULL,
  `coil_no` varchar(50) DEFAULT NULL,
  `roll_no` varchar(100) DEFAULT NULL,
  `width` decimal(10,2) DEFAULT NULL,
  `length` decimal(10,2) DEFAULT NULL,
  `actual_length` decimal(10,2) DEFAULT NULL,
  `length_type` varchar(20) DEFAULT NULL,
  `status` enum('IN','WAITING','APPROVED','REJECTED','DELIVERED') DEFAULT 'IN',
  `qc_comment` text,
  `is_completed` tinyint(1) DEFAULT '0',
  `stock_counted` tinyint(1) DEFAULT '0',
  `date_in` date NOT NULL DEFAULT (curdate()),
  `date_out` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
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
  `stock` decimal(10,2) DEFAULT NULL,
  `std_weight` decimal(10,4) DEFAULT '0.0000' COMMENT 'Standard weight for calculation',
  `recoiling_id` int DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'raw_material',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_production_roll` (`lot_no`,`coil_no`,`roll_no`),
  KEY `idx_recoiling_id` (`recoiling_id`),
  KEY `fk_slitting_std_wgt` (`product`),
  KEY `fk_slitting_mother` (`mother_id`),
  KEY `fk_slitting_log` (`from_log_id`),
  CONSTRAINT `fk_slitting_log` FOREIGN KEY (`from_log_id`) REFERENCES `raw_material_log` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_slitting_mother` FOREIGN KEY (`mother_id`) REFERENCES `mother_coil` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_slitting_std_wgt` FOREIGN KEY (`product`) REFERENCES `std_wgt` (`product_code`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `slitting_product`
--

LOCK TABLES `slitting_product` WRITE;
/*!40000 ALTER TABLE `slitting_product` DISABLE KEYS */;
/*!40000 ALTER TABLE `slitting_product` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `std_wgt`
--

DROP TABLE IF EXISTS `std_wgt`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `std_wgt` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_code` varchar(100) NOT NULL,
  `std_weight` decimal(10,4) NOT NULL COMMENT 'Standard weight for calculation',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_product` (`product_code`),
  KEY `idx_product` (`product_code`)
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Standard weight lookup table';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `std_wgt`
--

LOCK TABLES `std_wgt` WRITE;
/*!40000 ALTER TABLE `std_wgt` DISABLE KEYS */;
INSERT INTO `std_wgt` VALUES (1,'DS-3020',1.7300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(2,'DS-3825',2.1690,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(3,'DS-4525',2.2600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(4,'DS-5030',2.6600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(5,'DS-8460',5.1100,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(6,'GB-6440',3.5100,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(7,'GB-6440-S101',3.5100,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(8,'GB-7640',3.6700,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(9,'JZ-2520',1.6700,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(10,'JZ-2520-2C',1.6700,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(11,'JZ-2820',1.7000,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(12,'JZ-3020',1.7300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(13,'JZ-4020',1.8600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(14,'KB-6440',3.5120,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(15,'L1N2-2520-02',1.6700,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(16,'LN-1715-1',1.5600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(17,'LN-2520',1.6700,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(18,'LN-2520-02',1.6700,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(19,'LN-2520-04',1.6700,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(20,'LZ-2420',1.6100,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(21,'LZ-2520',1.6700,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(22,'MV-4020',1.7300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(23,'PS-6020',1.9100,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(24,'PS-8525',2.2400,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(25,'RS-3020',1.7300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(26,'RS-3825',2.1690,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(27,'RS-3825-04',2.1690,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(28,'RS-4020',1.8600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(29,'RS-4025',2.1900,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(30,'RS-4525',2.2600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(31,'RS-5030',2.6600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(32,'RS-6040',3.4600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(33,'RS-7050',4.2600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(34,'RU-5040-1',3.3300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(35,'RU-5040-1-S101',3.3300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(36,'RV-3825',2.1690,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(37,'TS-2620',1.6780,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(38,'TS-3020',1.7300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(39,'TS-3525',2.1300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(40,'TS-3525-SG',2.1300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(41,'TS-4025',2.1900,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(42,'TS-4525',2.2600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(43,'TS-5030',2.6600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(44,'TS-9080',6.5300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(45,'TS-9080-SG',6.5300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(46,'TU-2620',1.6780,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(47,'TU-2620-C',1.6790,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(48,'TU-3020',1.7300,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(49,'TU-4020',1.8600,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(50,'JV-3825',2.1690,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(51,'YW-2520-SG',1.6700,'2026-01-13 06:45:32','2026-01-13 06:45:32'),(52,'HBV-4020',1.7300,'2026-01-14 08:37:08','2026-01-14 08:37:08');
/*!40000 ALTER TABLE `std_wgt` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_raw_material`
--

DROP TABLE IF EXISTS `stock_raw_material`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
  CONSTRAINT `fk_mother_source` FOREIGN KEY (`source_id`) REFERENCES `mother_coil` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_raw_material`
--

LOCK TABLES `stock_raw_material` WRITE;
/*!40000 ALTER TABLE `stock_raw_material` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_raw_material` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'slitting','$2y$10$8utZ6odMkhIqAK/YZrNS2u7q8zDYRd/rXnNHhr1hzCub1b9evcF.K','slitting'),(2,'mkl3','$2y$10$uA0aX6ot0Mh9.5s/b/Iap.FQKh03/YzN2nCzaGmvGo4JtIzamvMSu','mkl3'),(3,'qc','$2y$10$8utZ6odMkhIqAK/YZrNS2u7q8zDYRd/rXnNHhr1hzCub1b9evcF.K','qc');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `waiting_approval`
--

DROP TABLE IF EXISTS `waiting_approval`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `waiting_approval`
--

LOCK TABLES `waiting_approval` WRITE;
/*!40000 ALTER TABLE `waiting_approval` DISABLE KEYS */;
/*!40000 ALTER TABLE `waiting_approval` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'slitting_db_test'
--

--
-- Dumping routines for database 'slitting_db_test'
--

--
-- Final view structure for view `production_yield_summary`
--

/*!50001 DROP VIEW IF EXISTS `production_yield_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `production_yield_summary` AS select `m`.`id` AS `mother_id`,`m`.`coil_no` AS `coil_no`,`m`.`product` AS `product`,cast(`m`.`width` as decimal(10,2)) AS `input_width`,sum(cast(`s`.`width` as decimal(10,2))) AS `output_width`,(cast(`m`.`width` as decimal(10,2)) - sum(cast(`s`.`width` as decimal(10,2)))) AS `waste_width`,((sum(cast(`s`.`width` as decimal(10,2))) / cast(`m`.`width` as decimal(10,2))) * 100) AS `yield_percentage`,`m`.`date_created` AS `date_created` from (`mother_coil` `m` left join `slitting_product` `s` on((`m`.`id` = `s`.`mother_id`))) group by `m`.`id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-29  8:51:06
