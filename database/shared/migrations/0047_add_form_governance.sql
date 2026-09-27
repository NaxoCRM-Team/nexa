-- M08 tenant form governance extends native EspoCRM Lead Capture. The native
-- lead_capture record remains the executable form and submission owner.

CREATE TABLE IF NOT EXISTS `nexa_form_profile` (
    `lead_capture_id` VARCHAR(17) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'draft',
    `version_number` INT UNSIGNED NOT NULL DEFAULT 0,
    `has_unpublished_changes` TINYINT(1) NOT NULL DEFAULT 1,
    `description` VARCHAR(1000) NULL,
    `draft_configuration_json` JSON NOT NULL,
    `consent_purpose_id` CHAR(36) NULL,
    `consent_channel` VARCHAR(24) NULL,
    `consent_label` VARCHAR(500) NULL,
    `progressive_profiling` TINYINT(1) NOT NULL DEFAULT 0,
    `conditional_rules_json` JSON NULL,
    `field_mapping_json` JSON NULL,
    `published_at` DATETIME(6) NULL,
    `archived_at` DATETIME(6) NULL,
    `created_by_id` VARCHAR(17) NULL,
    `modified_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`tenant_id`, `service_id`, `lead_capture_id`),
    KEY `idx_nexa_form_profile_status` (`tenant_id`, `service_id`, `status`, `modified_at`),
    CONSTRAINT `fk_nexa_form_profile_tenant_service`
        FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_form_profile_status`
        CHECK (`status` IN ('draft', 'published', 'archived')),
    CONSTRAINT `chk_nexa_form_profile_channel`
        CHECK (`consent_channel` IS NULL OR `consent_channel` IN ('email', 'phone', 'sms', 'whatsapp', 'linkedin', 'postal', 'live_chat'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_form_version` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `lead_capture_id` VARCHAR(17) NOT NULL,
    `version_number` INT UNSIGNED NOT NULL,
    `configuration_json` JSON NOT NULL,
    `configuration_hash` CHAR(64) NOT NULL,
    `published_by_id` VARCHAR(17) NULL,
    `published_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_form_version_scope_id` (`tenant_id`, `service_id`, `id`),
    UNIQUE KEY `uq_nexa_form_version_number` (`tenant_id`, `service_id`, `lead_capture_id`, `version_number`),
    KEY `idx_nexa_form_version_form` (`tenant_id`, `service_id`, `lead_capture_id`, `published_at`),
    CONSTRAINT `fk_nexa_form_version_profile`
        FOREIGN KEY (`tenant_id`, `service_id`, `lead_capture_id`)
        REFERENCES `nexa_form_profile` (`tenant_id`, `service_id`, `lead_capture_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
