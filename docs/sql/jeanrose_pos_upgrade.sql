-- ============================================================
-- Upgrade script for an EXISTING jeanrose_pos database.
-- Keeps your current data and is safe to run more than once:
-- it only adds what is missing.
--
-- Result: 12 tables
--   * sale gets cash_received, change_amount, void_reason,
--     voided_at, voided_by
--   * alert_notification is created
--   * password_reset_tokens is created
--
-- Back up the database first (Server > Data Export).
-- ============================================================

USE jeanrose_pos;

DROP PROCEDURE IF EXISTS jr_add_column;
DROP PROCEDURE IF EXISTS jr_add_sale_voided_by_fk;

DELIMITER $$

CREATE PROCEDURE jr_add_column(
  IN p_table VARCHAR(64),
  IN p_column VARCHAR(64),
  IN p_definition VARCHAR(255)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND COLUMN_NAME = p_column
  ) THEN
    SET @jr_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
    PREPARE jr_stmt FROM @jr_sql;
    EXECUTE jr_stmt;
    DEALLOCATE PREPARE jr_stmt;
  END IF;
END$$

CREATE PROCEDURE jr_add_sale_voided_by_fk()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sale'
      AND CONSTRAINT_NAME = 'fk_sale_voided_by'
  ) THEN
    ALTER TABLE sale
      ADD CONSTRAINT fk_sale_voided_by FOREIGN KEY (voided_by)
        REFERENCES user_account (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT;
  END IF;
END$$

DELIMITER ;

-- 1. Receipt and void details on sale
CALL jr_add_column('sale', 'cash_received', 'DECIMAL(12,2) NULL');
CALL jr_add_column('sale', 'change_amount', 'DECIMAL(12,2) NULL');
CALL jr_add_column('sale', 'void_reason',   'VARCHAR(255) NULL');
CALL jr_add_column('sale', 'voided_at',     'DATETIME NULL');
CALL jr_add_column('sale', 'voided_by',     'INT NULL');
CALL jr_add_sale_voided_by_fk();

-- 2. Alert tracking table
CREATE TABLE IF NOT EXISTS alert_notification (
  alert_id     INT AUTO_INCREMENT PRIMARY KEY,
  batch_id     INT NOT NULL,
  alert_type   ENUM('LOW STOCK','OUT OF STOCK','NEAR EXPIRY') NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  emailed_at   DATETIME NULL,
  resolved_at  DATETIME NULL,
  KEY idx_alert_batch_type (batch_id, alert_type),
  KEY idx_alert_open (resolved_at),
  CONSTRAINT fk_alert_batch FOREIGN KEY (batch_id)
    REFERENCES product_batch (batch_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 3. Password reset tokens table (hashed, expiring, one-time)
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  email       VARCHAR(50)  NOT NULL PRIMARY KEY,
  token       VARCHAR(255) NOT NULL,
  created_at  DATETIME NULL
) ENGINE=InnoDB;

-- Clean up the helper procedures
DROP PROCEDURE IF EXISTS jr_add_column;
DROP PROCEDURE IF EXISTS jr_add_sale_voided_by_fk;

-- Check: this should return 12
SELECT COUNT(*) AS table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'jeanrose_pos' AND TABLE_TYPE = 'BASE TABLE';
