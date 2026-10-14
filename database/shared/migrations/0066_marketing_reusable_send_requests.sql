-- Durable scoped request identities over the existing native marketing delivery queue.
CREATE TABLE IF NOT EXISTS `nexa_marketing_send_request` (
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `request_hash` CHAR(64) NOT NULL,
    `source_profile_id` CHAR(36) NOT NULL,
    `source_version` INT UNSIGNED NOT NULL,
    `contact_id` VARCHAR(24) NOT NULL,
    `profile_id` CHAR(36) NULL,
    `created_by_id` VARCHAR(24) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`tenant_id`,`service_id`,`request_hash`),
    KEY `idx_marketing_send_request_source` (`tenant_id`,`service_id`,`source_profile_id`,`created_at`),
    CONSTRAINT `fk_marketing_send_request_scope` FOREIGN KEY (`tenant_id`,`service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
