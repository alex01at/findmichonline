ALTER TABLE business_cards
    ADD COLUMN view_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_published;
