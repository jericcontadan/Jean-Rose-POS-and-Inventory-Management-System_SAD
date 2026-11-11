-- MySQL Script (Workbench style), updated to 12 tables
-- Based on the team's original jeanrose_pos script (10 tables).
--
-- Changes from the original:
--   1. sale: added cash_received, change_amount, void_reason,
--            voided_at, voided_by (+ foreign key to user_account)
--   2. NEW table password_reset_tokens (FR-03, Form B)
--   3. NEW table alert_notification (FR-07)
--
-- WARNING: this script DROPS and recreates every table, so it
-- erases existing data. Use it only to build a fresh database.
-- To keep existing data, run jeanrose_pos_upgrade.sql instead.

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- -----------------------------------------------------
-- Schema jeanrose_pos
-- -----------------------------------------------------
CREATE SCHEMA IF NOT EXISTS `jeanrose_pos` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ;
USE `jeanrose_pos` ;

-- -----------------------------------------------------
-- Table `jeanrose_pos`.`user_account`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`user_account` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`user_account` (
  `user_id` INT NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(50) NOT NULL,
  `username` VARCHAR(120) NOT NULL,
  `phone_number` VARCHAR(30) NULL DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('OWNER', 'CASHIER') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`user_id`),
  UNIQUE INDEX `uq_user_email` (`email` ASC) VISIBLE,
  UNIQUE INDEX `uq_user_username` (`username` ASC) VISIBLE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


-- -----------------------------------------------------
-- Table `jeanrose_pos`.`sale`
-- NEW: cash_received, change_amount, void_reason, voided_at, voided_by
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`sale` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`sale` (
  `sale_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `sale_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `subtotal` DECIMAL(12,2) NOT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL,
  `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT '0.00',
  `sale_status` ENUM('PAID', 'CANCELLED') NOT NULL DEFAULT 'PAID',
  `cash_received` DECIMAL(12,2) NULL DEFAULT NULL,
  `change_amount` DECIMAL(12,2) NULL DEFAULT NULL,
  `void_reason` VARCHAR(255) NULL DEFAULT NULL,
  `voided_at` DATETIME NULL DEFAULT NULL,
  `voided_by` INT NULL DEFAULT NULL,
  PRIMARY KEY (`sale_id`),
  INDEX `idx_sale_datetime` (`sale_datetime` ASC) VISIBLE,
  INDEX `fk_sale_user` (`user_id` ASC) VISIBLE,
  INDEX `fk_sale_voided_by` (`voided_by` ASC) VISIBLE,
  CONSTRAINT `fk_sale_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `jeanrose_pos`.`user_account` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_sale_voided_by`
    FOREIGN KEY (`voided_by`)
    REFERENCES `jeanrose_pos`.`user_account` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


-- -----------------------------------------------------
-- Table `jeanrose_pos`.`audit_log`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`audit_log` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`audit_log` (
  `audit_log_id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `sale_id` INT NULL DEFAULT NULL,
  `action_type` ENUM('LOGIN', 'SALE', 'VOID', 'INVENTORY_UPDATE') NOT NULL,
  `module` ENUM('SALES', 'INVENTORY') NOT NULL,
  `transaction_reference` VARCHAR(50) NULL DEFAULT NULL,
  `details` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_log_id`),
  INDEX `idx_audit_created` (`created_at` ASC) VISIBLE,
  INDEX `fk_audit_user` (`user_id` ASC) VISIBLE,
  INDEX `fk_audit_sale` (`sale_id` ASC) VISIBLE,
  CONSTRAINT `fk_audit_sale`
    FOREIGN KEY (`sale_id`)
    REFERENCES `jeanrose_pos`.`sale` (`sale_id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT `fk_audit_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `jeanrose_pos`.`user_account` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


-- -----------------------------------------------------
-- Table `jeanrose_pos`.`category`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`category` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`category` (
  `category_id` INT NOT NULL AUTO_INCREMENT,
  `category_name` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`category_id`),
  UNIQUE INDEX `uq_category_name` (`category_name` ASC) VISIBLE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


-- -----------------------------------------------------
-- Table `jeanrose_pos`.`supplier`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`supplier` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`supplier` (
  `supplier_id` INT NOT NULL AUTO_INCREMENT,
  `supplier_name` VARCHAR(120) NOT NULL,
  `contact_number` VARCHAR(30) NULL DEFAULT NULL,
  `email` VARCHAR(120) NULL DEFAULT NULL,
  `address` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`supplier_id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


-- -----------------------------------------------------
-- Table `jeanrose_pos`.`product`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`product` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`product` (
  `product_id` INT NOT NULL AUTO_INCREMENT,
  `category_id` INT NOT NULL,
  `supplier_id` INT NOT NULL,
  `product_name` VARCHAR(150) NOT NULL,
  `product_number` VARCHAR(60) NOT NULL,
  `cost_price` DECIMAL(12,2) NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL,
  `image_path` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`product_id`),
  UNIQUE INDEX `uq_product_number` (`product_number` ASC) VISIBLE,
  INDEX `fk_product_category` (`category_id` ASC) VISIBLE,
  INDEX `fk_product_supplier` (`supplier_id` ASC) VISIBLE,
  CONSTRAINT `fk_product_category`
    FOREIGN KEY (`category_id`)
    REFERENCES `jeanrose_pos`.`category` (`category_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_product_supplier`
    FOREIGN KEY (`supplier_id`)
    REFERENCES `jeanrose_pos`.`supplier` (`supplier_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


-- -----------------------------------------------------
-- Table `jeanrose_pos`.`product_batch`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`product_batch` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`product_batch` (
  `batch_id` INT NOT NULL AUTO_INCREMENT,
  `product_id` INT NOT NULL,
  `batch_number` VARCHAR(50) NOT NULL,
  `expiry_date` DATE NOT NULL,
  `quantity_in_batch` INT NOT NULL,
  `date_received` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`batch_id`),
  UNIQUE INDEX `uq_batch_per_product` (`product_id` ASC, `batch_number` ASC) VISIBLE,
  CONSTRAINT `fk_batch_product`
    FOREIGN KEY (`product_id`)
    REFERENCES `jeanrose_pos`.`product` (`product_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


-- -----------------------------------------------------
-- Table `jeanrose_pos`.`inventory`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`inventory` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`inventory` (
  `inventory_id` INT NOT NULL AUTO_INCREMENT,
  `batch_id` INT NOT NULL,
  `quantity_on_hand` INT NOT NULL DEFAULT '0',
  `reorder_level` INT NOT NULL DEFAULT '0',
  `stock_status` ENUM('LOW STOCK', 'OUT OF STOCK', 'OKAY', 'NEAR EXPIRY') NOT NULL DEFAULT 'OKAY',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`inventory_id`),
  INDEX `fk_inventory_batch` (`batch_id` ASC) VISIBLE,
  UNIQUE INDEX `batch_id_UNIQUE` (`batch_id` ASC) VISIBLE,
  CONSTRAINT `fk_inventory_batch`
    FOREIGN KEY (`batch_id`)
    REFERENCES `jeanrose_pos`.`product_batch` (`batch_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


-- -----------------------------------------------------
-- Table `jeanrose_pos`.`inventory_history`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`inventory_history` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`inventory_history` (
  `transaction_id` INT NOT NULL AUTO_INCREMENT,
  `inventory_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `quantity_change` INT NOT NULL,
  `transaction_type` ENUM('RESTOCK', 'SALE', 'VOID') NOT NULL,
  `transaction_datetime` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`transaction_id`),
  INDEX `fk_invhist_inventory` (`inventory_id` ASC) VISIBLE,
  INDEX `fk_invhist_batch` (`batch_id` ASC) VISIBLE,
  INDEX `fk_invhist_user` (`user_id` ASC) VISIBLE,
  CONSTRAINT `fk_invhist_batch`
    FOREIGN KEY (`batch_id`)
    REFERENCES `jeanrose_pos`.`product_batch` (`batch_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_invhist_inventory`
    FOREIGN KEY (`inventory_id`)
    REFERENCES `jeanrose_pos`.`inventory` (`inventory_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_invhist_user`
    FOREIGN KEY (`user_id`)
    REFERENCES `jeanrose_pos`.`user_account` (`user_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


-- -----------------------------------------------------
-- Table `jeanrose_pos`.`sale_item`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`sale_item` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`sale_item` (
  `sale_item_id` INT NOT NULL AUTO_INCREMENT,
  `sale_id` INT NOT NULL,
  `batch_id` INT NOT NULL,
  `quantity` INT NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL,
  `line_amount` DECIMAL(12,2) NOT NULL,
  `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`sale_item_id`),
  INDEX `fk_saleitem_sale` (`sale_id` ASC) VISIBLE,
  INDEX `fk_saleitem_batch` (`batch_id` ASC) VISIBLE,
  CONSTRAINT `fk_saleitem_batch`
    FOREIGN KEY (`batch_id`)
    REFERENCES `jeanrose_pos`.`product_batch` (`batch_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE,
  CONSTRAINT `fk_saleitem_sale`
    FOREIGN KEY (`sale_id`)
    REFERENCES `jeanrose_pos`.`sale` (`sale_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


-- -----------------------------------------------------
-- Table `jeanrose_pos`.`password_reset_tokens`   (NEW, 11th table)
-- Stores only the hashed, expiring, one-time link token or SMS code.
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`password_reset_tokens` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`password_reset_tokens` (
  `email` VARCHAR(50) NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`email`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


-- -----------------------------------------------------
-- Table `jeanrose_pos`.`alert_notification`   (NEW, 12th table)
-- Remembers which stock and expiry alerts were created and emailed,
-- so each alert is shown once and emailed once (FR-07).
-- -----------------------------------------------------
DROP TABLE IF EXISTS `jeanrose_pos`.`alert_notification` ;

CREATE TABLE IF NOT EXISTS `jeanrose_pos`.`alert_notification` (
  `alert_id` INT NOT NULL AUTO_INCREMENT,
  `batch_id` INT NOT NULL,
  `alert_type` ENUM('LOW STOCK', 'OUT OF STOCK', 'NEAR EXPIRY') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `emailed_at` DATETIME NULL DEFAULT NULL,
  `resolved_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`alert_id`),
  INDEX `idx_alert_batch_type` (`batch_id` ASC, `alert_type` ASC) VISIBLE,
  INDEX `idx_alert_open` (`resolved_at` ASC) VISIBLE,
  CONSTRAINT `fk_alert_batch`
    FOREIGN KEY (`batch_id`)
    REFERENCES `jeanrose_pos`.`product_batch` (`batch_id`)
    ON DELETE RESTRICT
    ON UPDATE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_0900_ai_ci;


SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
