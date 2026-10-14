-- Idempotent provider callbacks, monthly usage and native queue reconciliation.

ALTER TABLE `nexa_marketing_delivery_provider`
    ADD COLUMN IF NOT EXISTS `callback_token_hash` CHAR(64) NULL AFTER `credential_name`,
    ADD COLUMN IF NOT EXISTS `callback_token_prefix` CHAR(8) NULL AFTER `callback_token_hash`;

CREATE TABLE IF NOT EXISTS `nexa_marketing_delivery_message` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `profile_id` CHAR(36) NOT NULL,
    `email_queue_item_id` VARCHAR(24) NOT NULL,
    `provider_key` VARCHAR(32) NOT NULL,
    `provider_message_id` VARCHAR(255) NULL,
    `provider_token` VARCHAR(255) NULL,
    `recipient` VARCHAR(320) NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
    `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(1000) NULL,
    `submitted_at` DATETIME(6) NULL,
    `reconciled_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_delivery_queue_item` (`tenant_id`,`service_id`,`email_queue_item_id`),
    KEY `idx_nexa_delivery_provider_message` (`tenant_id`,`service_id`,`provider_message_id`),
    KEY `idx_nexa_delivery_status` (`tenant_id`,`service_id`,`status`,`modified_at`),
    CONSTRAINT `fk_nexa_delivery_message_scope` FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_delivery_message_profile` FOREIGN KEY (`profile_id`) REFERENCES `nexa_marketing_email_profile` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_marketing_monthly_usage` (
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `usage_month` DATE NOT NULL,
    `accepted_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `delivered_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`tenant_id`,`service_id`,`usage_month`),
    CONSTRAINT `fk_nexa_marketing_usage_scope` FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
