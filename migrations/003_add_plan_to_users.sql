ALTER TABLE users
    ADD COLUMN plan ENUM('free', 'pro') NOT NULL DEFAULT 'free' AFTER email;
