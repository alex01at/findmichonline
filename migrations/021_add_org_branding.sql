ALTER TABLE organizations
    ADD COLUMN logo_path VARCHAR(255) NULL AFTER seats,
    ADD COLUMN address VARCHAR(255) NULL AFTER logo_path,
    ADD COLUMN design VARCHAR(20) NULL AFTER address;

ALTER TABLE business_cards
    ADD COLUMN workplace VARCHAR(255) NULL AFTER address;
