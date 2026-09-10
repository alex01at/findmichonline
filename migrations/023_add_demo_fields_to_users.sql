ALTER TABLE users
    ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER plan,
    ADD COLUMN demo_expires_at DATETIME NULL AFTER is_demo,
    ADD COLUMN demo_ip VARCHAR(45) NULL AFTER demo_expires_at;
