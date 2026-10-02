-- Phase 5 public website collection sources. Public keys identify configuration, never trusted CRM identities.

CREATE TABLE IF NOT EXISTS `nexa_tracking_source` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` VARCHAR(64) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `public_key` CHAR(48) NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'active',
    `integration_mode` VARCHAR(24) NOT NULL DEFAULT 'managed',
    `allowed_origins_json` JSON NOT NULL,
    `created_by_id` VARCHAR(17) NULL,
    `modified_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_tracking_source_public_key` (`public_key`),
    UNIQUE KEY `uq_nexa_tracking_source_scope_name` (`tenant_id`,`service_id`,`name`),
    KEY `idx_nexa_tracking_source_scope_status` (`tenant_id`,`service_id`,`status`),
    CONSTRAINT `fk_nexa_tracking_source_tenant_service`
        FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_tracking_source_status` CHECK (`status` IN ('active','paused')),
    CONSTRAINT `chk_nexa_tracking_source_mode` CHECK (`integration_mode` IN ('managed','external','necessary_only'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
