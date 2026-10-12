-- Retain native queues and the existing scoped monthly ledger.
ALTER TABLE nexa_marketing_monthly_usage
    ADD COLUMN IF NOT EXISTS transactional_accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS transactional_delivered_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS transactional_failed_count INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE nexa_marketing_delivery_message
    ADD COLUMN IF NOT EXISTS usage_class VARCHAR(16) NOT NULL DEFAULT 'marketing',
    ADD COLUMN IF NOT EXISTS reserved_usage_month DATE NULL,
    ADD COLUMN IF NOT EXISTS reservation_released_at DATETIME(6) NULL;

-- NULL reservation months preserve the legacy callback accounting convention.
