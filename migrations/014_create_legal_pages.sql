CREATE TABLE legal_pages (
    slug VARCHAR(20) NOT NULL PRIMARY KEY,
    content_de TEXT NOT NULL,
    content_en TEXT NOT NULL,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO legal_pages (slug, content_de, content_en) VALUES
    ('impressum', '', ''),
    ('datenschutz', '', '');
