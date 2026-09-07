ALTER TABLE users
    ADD COLUMN stripe_customer_id VARCHAR(255) NULL AFTER plan,
    ADD COLUMN stripe_subscription_id VARCHAR(255) NULL AFTER stripe_customer_id,
    ADD COLUMN subscription_status VARCHAR(50) NULL AFTER stripe_subscription_id,
    ADD COLUMN cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0 AFTER subscription_status,
    ADD UNIQUE KEY uq_users_stripe_customer_id (stripe_customer_id);
