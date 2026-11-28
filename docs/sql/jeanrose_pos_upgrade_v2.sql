-- ============================================================
-- Upgrade v2 for an EXISTING jeanrose_pos database.
-- Keeps your current data. Run jeanrose_pos_upgrade.sql first
-- if your database is older than the 12-table version.
--
-- What it changes (still 12 tables):
--   * sale_item gets item_status, void_reason, voided_at, voided_by
--     (+ foreign key to user_account), product_name, product_number.
--     product_name and product_number are filled in for old rows.
--   * sale gets discount_type, discount_id_number,
--     discount_customer_name, discount_amount (Senior / PWD).
--   * inventory.stock_status also allows 'EXPIRED'.
--   * alert_notification.alert_type also allows 'EXPIRED'.
--   * inventory_history.transaction_type also allows 'ADJUSTMENT'.
--
-- Safety: only ALTER TABLE (ADD / MODIFY) and UPDATE.
-- No DROP, no TRUNCATE, no DELETE.
--
-- RUN IT ONCE. MySQL 8 has no "ADD COLUMN IF NOT EXISTS", so a
-- second run stops with "Duplicate column name" and changes nothing.
-- Each table is changed by ONE ALTER statement, and MySQL 8 DDL is
-- atomic, so a table is either fully upgraded or not touched.
--
-- Back up the database first (Server > Data Export).
-- ============================================================

USE jeanrose_pos;

-- 1. sale_item: per-item void + product snapshot at sale time
ALTER TABLE sale_item
  ADD COLUMN item_status    ENUM('SOLD','VOIDED') NOT NULL DEFAULT 'SOLD',
  ADD COLUMN void_reason    VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN voided_at      DATETIME     NULL DEFAULT NULL,
  ADD COLUMN voided_by      INT          NULL DEFAULT NULL,
  ADD COLUMN product_name   VARCHAR(150) NULL DEFAULT NULL,
  ADD COLUMN product_number VARCHAR(60)  NULL DEFAULT NULL,
  ADD INDEX fk_saleitem_voided_by (voided_by),
  ADD CONSTRAINT fk_saleitem_voided_by
    FOREIGN KEY (voided_by)
    REFERENCES user_account (user_id)
    ON DELETE RESTRICT
    ON UPDATE CASCADE;

-- 1b. Backfill product_name and product_number for existing rows.
--     sale_item links to product through product_batch.
--     Workbench "safe update" mode blocks this UPDATE, so turn it
--     off for this statement and restore it afterwards.
SET @OLD_SAFE_UPDATES = @@SQL_SAFE_UPDATES;
SET SQL_SAFE_UPDATES = 0;

UPDATE sale_item si
  JOIN product_batch pb ON pb.batch_id = si.batch_id
  JOIN product p        ON p.product_id = pb.product_id
SET si.product_name   = p.product_name,
    si.product_number = p.product_number
WHERE si.product_name IS NULL;

SET SQL_SAFE_UPDATES = @OLD_SAFE_UPDATES;

-- 2. sale: Senior / PWD discount details (old sales become NONE / 0.00)
ALTER TABLE sale
  ADD COLUMN discount_type          ENUM('NONE','SENIOR','PWD') NOT NULL DEFAULT 'NONE',
  ADD COLUMN discount_id_number     VARCHAR(30)   NULL DEFAULT NULL,
  ADD COLUMN discount_customer_name VARCHAR(120)  NULL DEFAULT NULL,
  ADD COLUMN discount_amount        DECIMAL(12,2) NOT NULL DEFAULT '0.00';

-- 3. ENUMs: every old value is kept, the new value is added at the
--    END, so existing rows keep their values.
ALTER TABLE inventory
  MODIFY stock_status ENUM('LOW STOCK','OUT OF STOCK','OKAY','NEAR EXPIRY','EXPIRED') NOT NULL DEFAULT 'OKAY';

ALTER TABLE alert_notification
  MODIFY alert_type ENUM('LOW STOCK','OUT OF STOCK','NEAR EXPIRY','EXPIRED') NOT NULL;

ALTER TABLE inventory_history
  MODIFY transaction_type ENUM('RESTOCK','SALE','VOID','ADJUSTMENT') NOT NULL;

-- Checks (read only)
SHOW COLUMNS FROM sale_item;
SHOW COLUMNS FROM sale;

-- This should return 0 (every sale line has its product name)
SELECT COUNT(*) AS sale_items_missing_name
FROM sale_item
WHERE product_name IS NULL;
