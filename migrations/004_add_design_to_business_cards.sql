ALTER TABLE business_cards
    ADD COLUMN design VARCHAR(20) NOT NULL DEFAULT 'classic' AFTER bio;
