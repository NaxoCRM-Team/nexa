-- M08 tenant landing pages. EspoCRM has no native landing-page publishing
-- model, so Nexa owns page drafts, immutable published versions and hooks.

CREATE TABLE IF NOT EXISTS `nexa_landing_page` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `public_key` CHAR(48) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `slug` VARCHAR(160) NOT NULL,
    `locale` VARCHAR(16) NOT NULL DEFAULT 'en',
    `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
    `version_number` INT UNSIGNED NOT NULL DEFAULT 0,
    `has_unpublished_changes` TINYINT(1) NOT NULL DEFAULT 1,
    `draft_configuration_json` JSON NOT NULL,
    `published_at` DATETIME(6) NULL,
    `archived_at` DATETIME(6) NULL,
    `created_by_id` VARCHAR(17) NULL,
    `modified_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_landing_page_scope_id` (`tenant_id`, `service_id`, `id`),
    UNIQUE KEY `uq_nexa_landing_page_key` (`public_key`),
    UNIQUE KEY `uq_nexa_landing_page_slug` (`tenant_id`, `service_id`, `locale`, `slug`),
    KEY `idx_nexa_landing_page_catalogue` (`tenant_id`, `service_id`, `status`, `modified_at`),
    CONSTRAINT `fk_nexa_landing_page_tenant_service`
        FOREIGN KEY (`tenant_id`, `service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_landing_page_status` CHECK (`status` IN ('draft', 'published', 'archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_landing_page_version` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `landing_page_id` CHAR(36) NOT NULL,
    `version_number` INT UNSIGNED NOT NULL,
    `configuration_json` JSON NOT NULL,
    `configuration_hash` CHAR(64) NOT NULL,
    `published_by_id` VARCHAR(17) NULL,
    `published_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_landing_version_scope_id` (`tenant_id`, `service_id`, `id`),
    UNIQUE KEY `uq_nexa_landing_version_number` (`tenant_id`, `service_id`, `landing_page_id`, `version_number`),
    KEY `idx_nexa_landing_version_page` (`tenant_id`, `service_id`, `landing_page_id`, `published_at`),
    CONSTRAINT `fk_nexa_landing_version_page`
        FOREIGN KEY (`tenant_id`, `service_id`, `landing_page_id`)
        REFERENCES `nexa_landing_page` (`tenant_id`, `service_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_landing_page_event` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `landing_page_id` CHAR(36) NOT NULL,
    `event_key` VARCHAR(96) NOT NULL,
    `event_type` VARCHAR(24) NOT NULL,
    `visitor_id` VARCHAR(64) NULL,
    `target_key` VARCHAR(160) NULL,
    `referrer` VARCHAR(1000) NULL,
    `user_agent_hash` CHAR(64) NULL,
    `page_version` INT UNSIGNED NOT NULL DEFAULT 0,
    `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_landing_event_scope_id` (`tenant_id`, `service_id`, `id`),
    UNIQUE KEY `uq_nexa_landing_event_key` (`tenant_id`, `service_id`, `event_key`, `event_type`),
    KEY `idx_nexa_landing_event_page` (`tenant_id`, `service_id`, `landing_page_id`, `event_type`, `occurred_at`),
    CONSTRAINT `fk_nexa_landing_event_page`
        FOREIGN KEY (`tenant_id`, `service_id`, `landing_page_id`)
        REFERENCES `nexa_landing_page` (`tenant_id`, `service_id`, `id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_landing_event_type` CHECK (`event_type` IN ('view', 'click'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
