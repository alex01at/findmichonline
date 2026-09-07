ALTER TABLE business_cards
    ADD COLUMN linkedin_url VARCHAR(255) NULL AFTER logo_path,
    ADD COLUMN instagram_url VARCHAR(255) NULL AFTER linkedin_url,
    ADD COLUMN facebook_url VARCHAR(255) NULL AFTER instagram_url,
    ADD COLUMN youtube_url VARCHAR(255) NULL AFTER facebook_url;
