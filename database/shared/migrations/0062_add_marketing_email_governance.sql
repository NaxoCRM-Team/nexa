-- Phase 6 governance layered on native EmailTemplate and MassEmail records.

CREATE TABLE IF NOT EXISTS `nexa_marketing_email_profile` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `mass_email_id` VARCHAR(17) NOT NULL,
    `delivery_mode` VARCHAR(32) NOT NULL DEFAULT 'campaign',
    `lifecycle_status` VARCHAR(32) NOT NULL DEFAULT 'draft',
    `purpose_id` CHAR(36) NULL,
    `preheader` VARCHAR(255) NULL,
    `version_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `submitted_by_id` VARCHAR(17) NULL,
    `submitted_at` DATETIME(6) NULL,
    `approved_by_id` VARCHAR(17) NULL,
    `approved_at` DATETIME(6) NULL,
    `created_by_id` VARCHAR(17) NULL,
    `modified_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_marketing_email_native` (`tenant_id`,`service_id`,`mass_email_id`),
    KEY `idx_nexa_marketing_email_status` (`tenant_id`,`service_id`,`lifecycle_status`,`modified_at`),
    CONSTRAINT `fk_nexa_marketing_email_tenant_service`
        FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_marketing_email_delivery_mode`
        CHECK (`delivery_mode` IN ('campaign','transactional','single_send')),
    CONSTRAINT `chk_nexa_marketing_email_lifecycle_status`
        CHECK (`lifecycle_status` IN ('draft','review','approved','scheduled','sending','paused','completed','failed','archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_marketing_email_version` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `profile_id` CHAR(36) NOT NULL,
    `version_number` INT UNSIGNED NOT NULL,
    `email_template_id` VARCHAR(17) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `preheader` VARCHAR(255) NULL,
    `body_html` MEDIUMTEXT NOT NULL,
    `body_text` MEDIUMTEXT NULL,
    `content_hash` CHAR(64) NOT NULL,
    `created_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_marketing_email_version` (`tenant_id`,`service_id`,`profile_id`,`version_number`),
    KEY `idx_nexa_marketing_email_version_profile` (`tenant_id`,`service_id`,`profile_id`,`created_at`),
    CONSTRAINT `fk_nexa_marketing_email_version_profile`
        FOREIGN KEY (`profile_id`) REFERENCES `nexa_marketing_email_profile` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_marketing_email_version_tenant_service`
        FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_marketing_email_event` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `profile_id` CHAR(36) NOT NULL,
    `event_type` VARCHAR(64) NOT NULL,
    `version_number` INT UNSIGNED NOT NULL,
    `payload_json` JSON NULL,
    `actor_user_id` VARCHAR(17) NULL,
    `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_nexa_marketing_email_event` (`tenant_id`,`service_id`,`profile_id`,`occurred_at`),
    CONSTRAINT `fk_nexa_marketing_email_event_profile`
        FOREIGN KEY (`profile_id`) REFERENCES `nexa_marketing_email_profile` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_marketing_email_event_tenant_service`
        FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
