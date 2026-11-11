-- Sample data for local testing only. Run as root AFTER jeanrose_pos.sql (tables must be empty).
-- User accounts are created separately with php artisan tinker (Hash::make) so no password is stored here.
USE jeanrose_pos;
INSERT INTO category (category_id, category_name) VALUES (1,'Skincare'),(2,'Haircare'),(3,'Cosmetics'),(4,'Supplements');
INSERT INTO supplier (supplier_id, supplier_name, contact_number) VALUES
 (1,'SY TRADING','09170000001'),(2,'ABC BEAUTY','09170000002'),(3,'VITA TRADING','09170000003');
INSERT INTO product (product_id, category_id, supplier_id, product_name, product_number, cost_price, unit_price, image_path) VALUES
 (1,1,1,'Serum','SK-0001',80.00,120.00,'images/placeholder.png'),
 (2,3,2,'Makeup','CS-0001',100.00,150.00,'images/placeholder.png'),
 (3,4,3,'Vitamins','SP-0001',60.00,90.00,'images/placeholder.png'),
 (4,1,1,'Lotion','SK-0002',50.00,80.00,'images/placeholder.png'),
 (5,2,2,'Shampoo','HC-0001',70.00,110.00,'images/placeholder.png'),
 (6,2,2,'Conditioner','HC-0002',75.00,115.00,'images/placeholder.png');
INSERT INTO product_batch (batch_id, product_id, batch_number, expiry_date, quantity_in_batch, date_received) VALUES
 (1,1,'B-2026-11','2026-11-25',10,'2026-09-01'),
 (2,2,'B-2027-03','2027-03-20',15,'2026-10-01'),
 (3,3,'B-2027-06','2027-06-01',20,'2026-10-01'),
 (4,4,'B-2027-05','2027-05-10',20,'2026-10-01'),
 (5,5,'B-2027-08','2027-08-15',20,'2026-10-15'),
 (6,6,'B-2027-09','2027-09-01',12,'2026-10-15');
INSERT INTO inventory (inventory_id, batch_id, quantity_on_hand, reorder_level, stock_status) VALUES
 (1,1,10,5,'NEAR EXPIRY'),(2,2,15,5,'OKAY'),(3,3,2,5,'LOW STOCK'),
 (4,4,0,5,'OUT OF STOCK'),(5,5,20,5,'OKAY'),(6,6,12,5,'OKAY');
-- Serum is near expiry only while its expiry date is within NEAR_EXPIRY_DAYS of today. Change the date if needed.
