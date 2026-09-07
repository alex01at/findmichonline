ALTER TABLE users
    ADD COLUMN password_reset_token VARCHAR(64) NULL AFTER password_hash,
    ADD COLUMN password_reset_expires_at DATETIME NULL AFTER password_reset_token,
    ADD UNIQUE KEY uq_users_password_reset_token (password_reset_token);
