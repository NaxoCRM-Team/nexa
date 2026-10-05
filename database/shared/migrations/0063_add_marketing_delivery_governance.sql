-- Tenant-owned marketing delivery providers, sending domains, suppressions and reconciliation.

CREATE TABLE IF NOT EXISTS `nexa_marketing_delivery_provider` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `provider_key` VARCHAR(32) NOT NULL DEFAULT 'postal',
    `api_base_url` VARCHAR(512) NOT NULL,
    `server_name` VARCHAR(128) NULL,
    `credential_name` VARCHAR(128) NOT NULL DEFAULT 'api-key',
    `status` VARCHAR(32) NOT NULL DEFAULT 'configured',
    `monthly_send_limit` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_by_id` VARCHAR(24) NULL,
    `modified_by_id` VARCHAR(24) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_marketing_provider_scope` (`tenant_id`,`service_id`),
    CONSTRAINT `fk_nexa_marketing_provider_scope` FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_marketing_provider_status` CHECK (`status` IN ('configured','ready','disabled','error'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_marketing_sending_domain` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `domain_name` VARCHAR(253) NOT NULL,
    `verification_token` CHAR(48) NOT NULL,
    `spf_include` VARCHAR(253) NULL,
    `dkim_host` VARCHAR(253) NULL,
    `dkim_value` TEXT NULL,
    `return_path_host` VARCHAR(253) NULL,
    `return_path_value` VARCHAR(253) NULL,
    `ownership_status` VARCHAR(24) NOT NULL DEFAULT 'pending',
    `spf_status` VARCHAR(24) NOT NULL DEFAULT 'pending',
    `dkim_status` VARCHAR(24) NOT NULL DEFAULT 'pending',
    `dmarc_status` VARCHAR(24) NOT NULL DEFAULT 'pending',
    `return_path_status` VARCHAR(24) NOT NULL DEFAULT 'pending',
    `last_checked_at` DATETIME(6) NULL,
    `verified_at` DATETIME(6) NULL,
    `created_by_id` VARCHAR(24) NULL,
    `modified_by_id` VARCHAR(24) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_marketing_domain_scope` (`tenant_id`,`service_id`,`domain_name`),
    KEY `idx_nexa_marketing_domain_status` (`tenant_id`,`service_id`,`ownership_status`,`spf_status`,`dkim_status`),
    CONSTRAINT `fk_nexa_marketing_domain_scope` FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_marketing_suppression` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `email_address` VARCHAR(320) NOT NULL,
    `reason` VARCHAR(32) NOT NULL,
    `source` VARCHAR(64) NOT NULL,
    `provider_message_id` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `released_at` DATETIME(6) NULL,
    `released_by_id` VARCHAR(24) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_marketing_suppression` (`tenant_id`,`service_id`,`email_address`),
    KEY `idx_nexa_marketing_suppression_active` (`tenant_id`,`service_id`,`is_active`,`reason`),
    CONSTRAINT `fk_nexa_marketing_suppression_scope` FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_marketing_suppression_reason` CHECK (`reason` IN ('unsubscribe','hard_bounce','complaint','manual','invalid'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_marketing_delivery_event` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `profile_id` CHAR(36) NULL,
    `provider_key` VARCHAR(32) NOT NULL,
    `provider_event_id` VARCHAR(255) NOT NULL,
    `provider_message_id` VARCHAR(255) NULL,
    `recipient` VARCHAR(320) NULL,
    `event_type` VARCHAR(32) NOT NULL,
    `payload_json` JSON NULL,
    `occurred_at` DATETIME(6) NOT NULL,
    `received_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_marketing_provider_event` (`tenant_id`,`service_id`,`provider_key`,`provider_event_id`),
    KEY `idx_nexa_marketing_delivery_event` (`tenant_id`,`service_id`,`profile_id`,`occurred_at`),
    CONSTRAINT `fk_nexa_marketing_delivery_event_scope` FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_marketing_delivery_profile` FOREIGN KEY (`profile_id`) REFERENCES `nexa_marketing_email_profile` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
