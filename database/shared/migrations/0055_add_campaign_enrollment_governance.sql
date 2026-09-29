-- M09 governed campaign definitions and consent-aware enrollment.
-- The native campaign and target-list tables remain the canonical CRM records.

CREATE TABLE IF NOT EXISTS `nexa_campaign_profile` (
    `campaign_id` VARCHAR(17) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `workflow_status` VARCHAR(16) NOT NULL DEFAULT 'draft',
    `channel` VARCHAR(24) NOT NULL DEFAULT 'email',
    `purpose_id` CHAR(36) NULL,
    `enrollment_mode` VARCHAR(16) NOT NULL DEFAULT 'snapshot',
    `audience_ids_json` JSON NOT NULL,
    `exclusion_ids_json` JSON NOT NULL,
    `version_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `matched_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `eligible_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `suppressed_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_evaluated_at` DATETIME(6) NULL,
    `last_evaluated_by_id` VARCHAR(17) NULL,
    `created_by_id` VARCHAR(17) NULL,
    `modified_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`tenant_id`, `service_id`, `campaign_id`),
    KEY `idx_nexa_campaign_profile_status` (`tenant_id`, `service_id`, `workflow_status`, `modified_at`),
    CONSTRAINT `fk_nexa_campaign_profile_tenant_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_campaign_profile_status` CHECK (`workflow_status` IN ('draft','active','paused','completed','archived')),
    CONSTRAINT `chk_nexa_campaign_enrollment_mode` CHECK (`enrollment_mode` IN ('snapshot','continuous'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_campaign_version` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `campaign_id` VARCHAR(17) NOT NULL,
    `version_number` INT UNSIGNED NOT NULL,
    `configuration_json` JSON NOT NULL,
    `configuration_hash` CHAR(64) NOT NULL,
    `created_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_campaign_version` (`tenant_id`, `service_id`, `campaign_id`, `version_number`),
    KEY `idx_nexa_campaign_version_campaign` (`tenant_id`, `service_id`, `campaign_id`, `created_at`),
    CONSTRAINT `fk_nexa_campaign_version_profile` FOREIGN KEY (`tenant_id`, `service_id`, `campaign_id`)
        REFERENCES `nexa_campaign_profile` (`tenant_id`, `service_id`, `campaign_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_campaign_enrollment` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `campaign_id` VARCHAR(17) NOT NULL,
    `contact_id` VARCHAR(17) NOT NULL,
    `campaign_version` INT UNSIGNED NOT NULL,
    `status` VARCHAR(16) NOT NULL,
    `reason_code` VARCHAR(64) NOT NULL,
    `reason_json` JSON NULL,
    `enrolled_at` DATETIME(6) NULL,
    `exited_at` DATETIME(6) NULL,
    `decided_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_campaign_enrollment_contact` (`tenant_id`, `service_id`, `campaign_id`, `contact_id`),
    KEY `idx_nexa_campaign_enrollment_status` (`tenant_id`, `service_id`, `campaign_id`, `status`),
    KEY `idx_nexa_campaign_enrollment_contact` (`tenant_id`, `service_id`, `contact_id`, `modified_at`),
    CONSTRAINT `fk_nexa_campaign_enrollment_profile` FOREIGN KEY (`tenant_id`, `service_id`, `campaign_id`)
        REFERENCES `nexa_campaign_profile` (`tenant_id`, `service_id`, `campaign_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_campaign_enrollment_status` CHECK (`status` IN ('enrolled','suppressed','exited'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_campaign_event` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `campaign_id` VARCHAR(17) NOT NULL,
    `contact_id` VARCHAR(17) NULL,
    `event_type` VARCHAR(40) NOT NULL,
    `campaign_version` INT UNSIGNED NOT NULL,
    `payload_json` JSON NULL,
    `actor_user_id` VARCHAR(17) NULL,
    `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_nexa_campaign_event_campaign` (`tenant_id`, `service_id`, `campaign_id`, `occurred_at`),
    KEY `idx_nexa_campaign_event_contact` (`tenant_id`, `service_id`, `contact_id`, `occurred_at`),
    CONSTRAINT `fk_nexa_campaign_event_profile` FOREIGN KEY (`tenant_id`, `service_id`, `campaign_id`)
        REFERENCES `nexa_campaign_profile` (`tenant_id`, `service_id`, `campaign_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
