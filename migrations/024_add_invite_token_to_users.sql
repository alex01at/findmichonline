ALTER TABLE users
    ADD COLUMN invite_token_hash VARCHAR(64) NULL AFTER remember_token_expires_at,
    ADD COLUMN invite_token_expires_at DATETIME NULL AFTER invite_token_hash,
    ADD UNIQUE KEY uq_users_invite_token_hash (invite_token_hash);
