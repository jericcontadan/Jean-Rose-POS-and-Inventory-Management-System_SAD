-- NOT NEEDED ANYMORE: password_reset_tokens is already inside docs/sql/jeanrose_pos.sql
-- and docs/sql/jeanrose_pos_upgrade.sql. Kept only so old instructions do not break.
CREATE TABLE IF NOT EXISTS jeanrose_pos.password_reset_tokens (
  email VARCHAR(50) NOT NULL PRIMARY KEY,
  token VARCHAR(255) NOT NULL,
  created_at DATETIME NULL
);
