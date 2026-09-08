ALTER TABLE business_cards
    ADD COLUMN booking_url VARCHAR(255) NULL AFTER youtube_url,
    ADD COLUMN clicks_booking INT UNSIGNED NOT NULL DEFAULT 0 AFTER clicks_youtube;

CREATE TABLE card_offerings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_card_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    price VARCHAR(50) NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_card_offerings_card FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_card_offerings_card ON card_offerings (business_card_id);
