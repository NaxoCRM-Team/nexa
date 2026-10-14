CREATE TABLE IF NOT EXISTS `nexa_marketing_integration_grant` (
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `user_id` VARCHAR(24) NOT NULL,
    `source_profile_id` CHAR(36) NOT NULL,
    `source_version` INT UNSIGNED NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME NULL,
    `granted_by_id` VARCHAR(24) NOT NULL,
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`tenant_id`,`service_id`,`user_id`,`source_profile_id`,`source_version`),
    CONSTRAINT `fk_marketing_integration_grant_scope` FOREIGN KEY (`tenant_id`,`service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
