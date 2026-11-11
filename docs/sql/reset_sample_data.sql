-- ============================================================
-- Reset sample data for LOCAL TESTING ONLY (database jeanrose_pos).
-- Deletes all products, batches, stock, sales and alerts, then
-- loads 6 sample products. It KEEPS user accounts and the audit log.
-- Run in MySQL Workbench as root. Safe to run again any time.
-- NEVER run this on the real shop database.
-- ============================================================
USE jeanrose_pos;

SET SQL_SAFE_UPDATES = 0;

-- 1. Clear old rows (children first, because of foreign keys)
DELETE FROM alert_notification;
DELETE FROM inventory_history;
DELETE FROM sale_item;
DELETE FROM sale;            -- audit_log rows keep their text; sale_id becomes NULL
DELETE FROM inventory;
DELETE FROM product_batch;
DELETE FROM product;
DELETE FROM supplier;
DELETE FROM category;

-- 2. Start receipt numbers again from S-0001
ALTER TABLE sale AUTO_INCREMENT = 1;
ALTER TABLE sale_item AUTO_INCREMENT = 1;
ALTER TABLE inventory_history AUTO_INCREMENT = 1;
ALTER TABLE alert_notification AUTO_INCREMENT = 1;

-- 3. Sample data (matches the mockup: Serum near expiry, Vitamins low, Lotion out)
INSERT INTO category (category_id, category_name) VALUES
 (1,'Skincare'),(2,'Haircare'),(3,'Cosmetics'),(4,'Supplements');

INSERT INTO supplier (supplier_id, supplier_name, contact_number) VALUES
 (1,'SY TRADING','09170000001'),(2,'ABC BEAUTY','09170000002'),(3,'VITA TRADING','09170000003');

INSERT INTO product (product_id, category_id, supplier_id, product_name, product_number, cost_price, unit_price, image_path) VALUES
 (1,1,1,'Serum','SK-0001',80.00,120.00,'images/placeholder.png'),
 (2,3,2,'Makeup','CS-0001',100.00,150.00,'images/placeholder.png'),
 (3,4,3,'Vitamins','SP-0001',60.00,90.00,'images/placeholder.png'),
 (4,1,1,'Lotion','SK-0002',50.00,80.00,'images/placeholder.png'),
 (5,2,2,'Shampoo','HC-0001',70.00,110.00,'images/placeholder.png'),
 (6,2,2,'Conditioner','HC-0002',75.00,115.00,'images/placeholder.png');

-- Serum expires 14 days from today, so it is always "near expiry" when you run this.
INSERT INTO product_batch (batch_id, product_id, batch_number, expiry_date, quantity_in_batch, date_received) VALUES
 (1,1,'B-SERUM-01', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 10, DATE_SUB(CURDATE(), INTERVAL 60 DAY)),
 (2,2,'B-MAKEUP-01', DATE_ADD(CURDATE(), INTERVAL 130 DAY), 15, DATE_SUB(CURDATE(), INTERVAL 40 DAY)),
 (3,3,'B-VITA-01',  DATE_ADD(CURDATE(), INTERVAL 200 DAY), 20, DATE_SUB(CURDATE(), INTERVAL 40 DAY)),
 (4,4,'B-LOTION-01',DATE_ADD(CURDATE(), INTERVAL 180 DAY), 20, DATE_SUB(CURDATE(), INTERVAL 40 DAY)),
 (5,5,'B-SHAMP-01', DATE_ADD(CURDATE(), INTERVAL 270 DAY), 20, DATE_SUB(CURDATE(), INTERVAL 25 DAY)),
 (6,6,'B-COND-01',  DATE_ADD(CURDATE(), INTERVAL 290 DAY), 12, DATE_SUB(CURDATE(), INTERVAL 25 DAY));

INSERT INTO inventory (inventory_id, batch_id, quantity_on_hand, reorder_level, stock_status) VALUES
 (1,1,10,5,'NEAR EXPIRY'),
 (2,2,15,5,'OKAY'),
 (3,3,2,5,'LOW STOCK'),
 (4,4,0,5,'OUT OF STOCK'),
 (5,5,20,5,'OKAY'),
 (6,6,12,5,'OKAY');

SET SQL_SAFE_UPDATES = 1;

-- Check: products 6, batches 6, inventory 6, sales 0
SELECT (SELECT COUNT(*) FROM product) AS products,
       (SELECT COUNT(*) FROM product_batch) AS batches,
       (SELECT COUNT(*) FROM inventory) AS inventory_rows,
       (SELECT COUNT(*) FROM sale) AS sales,
       (SELECT COUNT(*) FROM user_account) AS accounts_kept;
