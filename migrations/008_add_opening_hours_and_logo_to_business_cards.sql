ALTER TABLE business_cards
    ADD COLUMN opening_hours TEXT NULL AFTER bio,
    ADD COLUMN logo_path VARCHAR(255) NULL AFTER opening_hours;
