-- M09 tenant segment governance extends native EspoCRM Target Lists.
-- target_list and contact_target_list remain the canonical audience and membership tables.

CREATE TABLE IF NOT EXISTS `nexa_segment_definition` (
    `target_list_id` VARCHAR(17) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `segment_type` VARCHAR(16) NOT NULL DEFAULT 'static',
    `status` VARCHAR(16) NOT NULL DEFAULT 'active',
    `match_mode` VARCHAR(8) NOT NULL DEFAULT 'all',
    `rules_json` JSON NOT NULL,
    `version_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `member_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `eligible_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `suppressed_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_calculated_at` DATETIME(6) NULL,
    `last_calculated_by_id` VARCHAR(17) NULL,
    `created_by_id` VARCHAR(17) NULL,
    `modified_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`tenant_id`, `service_id`, `target_list_id`),
    KEY `idx_nexa_segment_status` (`tenant_id`, `service_id`, `status`, `modified_at`),
    CONSTRAINT `fk_nexa_segment_tenant_service`
        FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_segment_type` CHECK (`segment_type` IN ('static', 'dynamic')),
    CONSTRAINT `chk_nexa_segment_status` CHECK (`status` IN ('active', 'archived')),
    CONSTRAINT `chk_nexa_segment_match` CHECK (`match_mode` IN ('all', 'any'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_segment_version` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `target_list_id` VARCHAR(17) NOT NULL,
    `version_number` INT UNSIGNED NOT NULL,
    `segment_type` VARCHAR(16) NOT NULL,
    `match_mode` VARCHAR(8) NOT NULL,
    `rules_json` JSON NOT NULL,
    `configuration_hash` CHAR(64) NOT NULL,
    `created_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_segment_version_scope` (`tenant_id`, `service_id`, `target_list_id`, `version_number`),
    KEY `idx_nexa_segment_version_list` (`tenant_id`, `service_id`, `target_list_id`, `created_at`),
    CONSTRAINT `fk_nexa_segment_version_definition`
        FOREIGN KEY (`tenant_id`, `service_id`, `target_list_id`)
        REFERENCES `nexa_segment_definition` (`tenant_id`, `service_id`, `target_list_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_segment_run` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `target_list_id` VARCHAR(17) NOT NULL,
    `version_number` INT UNSIGNED NOT NULL,
    `status` VARCHAR(16) NOT NULL,
    `matched_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `entered_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `exited_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `eligible_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `suppressed_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `explanation_json` JSON NULL,
    `started_by_id` VARCHAR(17) NULL,
    `started_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `completed_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_nexa_segment_run_list` (`tenant_id`, `service_id`, `target_list_id`, `started_at`),
    CONSTRAINT `fk_nexa_segment_run_definition`
        FOREIGN KEY (`tenant_id`, `service_id`, `target_list_id`)
        REFERENCES `nexa_segment_definition` (`tenant_id`, `service_id`, `target_list_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_segment_run_status` CHECK (`status` IN ('running', 'completed', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_segment_membership_event` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `target_list_id` VARCHAR(17) NOT NULL,
    `run_id` CHAR(36) NOT NULL,
    `contact_id` VARCHAR(17) NOT NULL,
    `event_type` VARCHAR(8) NOT NULL,
    `explanation_json` JSON NULL,
    `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_segment_membership_event` (`tenant_id`, `service_id`, `run_id`, `contact_id`, `event_type`),
    KEY `idx_nexa_segment_membership_contact` (`tenant_id`, `service_id`, `contact_id`, `occurred_at`),
    CONSTRAINT `fk_nexa_segment_membership_run` FOREIGN KEY (`run_id`) REFERENCES `nexa_segment_run` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_segment_membership_event` CHECK (`event_type` IN ('entered', 'exited'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
