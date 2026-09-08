CREATE TABLE card_gallery_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_card_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_card_gallery_images_card FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_card_gallery_images_card ON card_gallery_images (business_card_id);
