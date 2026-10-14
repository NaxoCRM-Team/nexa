-- Independent, explicitly public email-image snapshots over native Attachment storage.
CREATE TABLE IF NOT EXISTS `nexa_marketing_image_publication` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `asset_id` CHAR(36) NOT NULL,
    `attachment_id` VARCHAR(24) NOT NULL,
    `public_token` CHAR(48) NOT NULL,
    `display_name` VARCHAR(255) NOT NULL,
    `alt_text` VARCHAR(500) NULL,
    `mime_type` VARCHAR(64) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL,
    `checksum_sha256` CHAR(64) NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'published',
    `created_by_id` VARCHAR(24) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `revoked_by_id` VARCHAR(24) NULL,
    `revoked_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_marketing_image_token` (`public_token`),
    KEY `idx_nexa_marketing_image_catalog` (`tenant_id`,`service_id`,`status`,`created_at`),
    KEY `idx_nexa_marketing_image_source` (`tenant_id`,`service_id`,`asset_id`,`checksum_sha256`),
    CONSTRAINT `fk_nexa_marketing_image_scope` FOREIGN KEY (`tenant_id`,`service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
