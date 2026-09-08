ALTER TABLE business_cards
    ADD COLUMN use_custom_colors TINYINT(1) NOT NULL DEFAULT 0 AFTER design,
    ADD COLUMN color_background VARCHAR(7) NULL AFTER use_custom_colors,
    ADD COLUMN color_header VARCHAR(7) NULL AFTER color_background,
    ADD COLUMN color_content VARCHAR(7) NULL AFTER color_header,
    ADD COLUMN color_footer VARCHAR(7) NULL AFTER color_content;
