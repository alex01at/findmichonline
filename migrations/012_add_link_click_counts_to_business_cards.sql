ALTER TABLE business_cards
    ADD COLUMN clicks_phone INT UNSIGNED NOT NULL DEFAULT 0 AFTER view_count,
    ADD COLUMN clicks_email INT UNSIGNED NOT NULL DEFAULT 0 AFTER clicks_phone,
    ADD COLUMN clicks_website INT UNSIGNED NOT NULL DEFAULT 0 AFTER clicks_email,
    ADD COLUMN clicks_address INT UNSIGNED NOT NULL DEFAULT 0 AFTER clicks_website,
    ADD COLUMN clicks_linkedin INT UNSIGNED NOT NULL DEFAULT 0 AFTER clicks_address,
    ADD COLUMN clicks_instagram INT UNSIGNED NOT NULL DEFAULT 0 AFTER clicks_linkedin,
    ADD COLUMN clicks_facebook INT UNSIGNED NOT NULL DEFAULT 0 AFTER clicks_instagram,
    ADD COLUMN clicks_youtube INT UNSIGNED NOT NULL DEFAULT 0 AFTER clicks_facebook;
