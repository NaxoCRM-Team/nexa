ALTER TABLE nexa_marketing_delivery_message
    ADD COLUMN IF NOT EXISTS provider_record_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS last_inspected_at DATETIME(6) NULL,
    ADD COLUMN IF NOT EXISTS inspection_attempt_count INT UNSIGNED NOT NULL DEFAULT 0;
