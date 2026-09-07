ALTER TABLE users
    ADD COLUMN remember_token_hash VARCHAR(64) NULL AFTER password_reset_expires_at,
    ADD COLUMN remember_token_expires_at DATETIME NULL AFTER remember_token_hash,
    ADD UNIQUE KEY uq_users_remember_token_hash (remember_token_hash);
