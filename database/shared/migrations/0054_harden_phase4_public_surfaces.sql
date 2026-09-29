-- Phase 4 public-surface abuse protection.
-- One bounded counter is shared by public Forms, cookie receipts and landing pages.

CREATE TABLE IF NOT EXISTS `nexa_public_rate_limit` (
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` VARCHAR(64) NOT NULL,
    `scope_key` VARCHAR(160) NOT NULL,
    `fingerprint_hash` CHAR(64) NOT NULL,
    `window_started_at` DATETIME(6) NOT NULL,
    `request_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `blocked_until` DATETIME(6) NULL,
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`tenant_id`, `service_id`, `scope_key`, `fingerprint_hash`),
    KEY `IDX_NEXA_PUBLIC_RATE_LIMIT_EXPIRY` (`updated_at`),
    CONSTRAINT `FK_NEXA_PUBLIC_RATE_LIMIT_TENANT`
        FOREIGN KEY (`tenant_id`) REFERENCES `nexa_tenant` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
