ALTER TABLE business_cards
    ADD COLUMN whatsapp VARCHAR(50) NULL AFTER phone,
    ADD COLUMN photo_path VARCHAR(255) NULL AFTER logo_path,
    ADD COLUMN clicks_whatsapp INT UNSIGNED NOT NULL DEFAULT 0 AFTER clicks_booking,
    ADD COLUMN onboarding_step TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER is_published,
    ADD COLUMN onboarding_completed_at DATETIME NULL AFTER onboarding_step;

-- Existing cards predate the wizard - don't send their owners back through onboarding.
UPDATE business_cards SET onboarding_completed_at = created_at, onboarding_step = 9;
