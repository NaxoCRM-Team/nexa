-- M08 governed asset catalogue layered on native Attachment, Document and
-- DocumentFolder records. File bytes remain in the configured Espo storage.

CREATE TABLE IF NOT EXISTS `nexa_asset_profile` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `current_attachment_id` VARCHAR(17) NOT NULL,
    `document_id` VARCHAR(17) NULL,
    `folder_id` VARCHAR(17) NULL,
    `display_name` VARCHAR(255) NOT NULL,
    `description` VARCHAR(1000) NULL,
    `alt_text` VARCHAR(500) NULL,
    `locale` VARCHAR(16) NOT NULL DEFAULT 'en',
    `access_scope` VARCHAR(16) NOT NULL DEFAULT 'internal',
    `status` VARCHAR(16) NOT NULL DEFAULT 'active',
    `tags_json` JSON NULL,
    `version_number` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_by_id` VARCHAR(17) NULL,
    `modified_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    `archived_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_asset_scope_id` (`tenant_id`, `service_id`, `id`),
    UNIQUE KEY `uq_nexa_asset_attachment` (`tenant_id`, `service_id`, `current_attachment_id`),
    KEY `idx_nexa_asset_catalogue` (`tenant_id`, `service_id`, `status`, `modified_at`),
    KEY `idx_nexa_asset_folder` (`tenant_id`, `service_id`, `folder_id`),
    CONSTRAINT `fk_nexa_asset_tenant_service`
        FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_asset_access`
        CHECK (`access_scope` IN ('internal', 'public')),
    CONSTRAINT `chk_nexa_asset_status`
        CHECK (`status` IN ('active', 'archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_asset_version` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `asset_id` CHAR(36) NOT NULL,
    `attachment_id` VARCHAR(17) NOT NULL,
    `version_number` INT UNSIGNED NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(160) NULL,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `checksum_sha256` CHAR(64) NULL,
    `created_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_asset_version_scope_id` (`tenant_id`, `service_id`, `id`),
    UNIQUE KEY `uq_nexa_asset_version_number` (`tenant_id`, `service_id`, `asset_id`, `version_number`),
    KEY `idx_nexa_asset_version_asset` (`tenant_id`, `service_id`, `asset_id`, `created_at`),
    CONSTRAINT `fk_nexa_asset_version_profile`
        FOREIGN KEY (`tenant_id`, `service_id`, `asset_id`)
        REFERENCES `nexa_asset_profile` (`tenant_id`, `service_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_asset_event` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `asset_id` CHAR(36) NOT NULL,
    `event_type` VARCHAR(24) NOT NULL,
    `actor_user_id` VARCHAR(17) NULL,
    `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_asset_event_scope_id` (`tenant_id`, `service_id`, `id`),
    KEY `idx_nexa_asset_event_asset` (`tenant_id`, `service_id`, `asset_id`, `event_type`, `occurred_at`),
    CONSTRAINT `fk_nexa_asset_event_profile`
        FOREIGN KEY (`tenant_id`, `service_id`, `asset_id`)
        REFERENCES `nexa_asset_profile` (`tenant_id`, `service_id`, `id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_asset_event_type`
        CHECK (`event_type` IN ('upload', 'download', 'metadata_update', 'archive', 'restore'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
