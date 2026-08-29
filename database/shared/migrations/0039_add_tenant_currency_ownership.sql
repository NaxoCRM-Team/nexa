-- Tenant-owned currency configuration layered over EspoCRM's native currency engine.
CREATE TABLE IF NOT EXISTS `nexa_tenant_currency_profile` (
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `base_currency` CHAR(3) NOT NULL DEFAULT 'USD',
    `default_currency` CHAR(3) NOT NULL DEFAULT 'USD',
    `modified_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`tenant_id`, `service_id`),
    CONSTRAINT `fk_nexa_currency_profile_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_tenant_currency_rate` (
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `currency_code` CHAR(3) NOT NULL,
    `rate` DECIMAL(24,10) NOT NULL DEFAULT 1,
    `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`tenant_id`, `service_id`, `currency_code`),
    KEY `idx_nexa_currency_rate_order` (`tenant_id`, `service_id`, `position`, `currency_code`),
    CONSTRAINT `fk_nexa_currency_rate_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_currency_rate_positive` CHECK (`rate` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_tenant_currency_history` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `base_currency` CHAR(3) NOT NULL,
    `default_currency` CHAR(3) NOT NULL,
    `currencies_json` LONGTEXT NOT NULL,
    `changed_by_id` VARCHAR(17) NULL,
    `changed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_nexa_currency_history` (`tenant_id`, `service_id`, `changed_at`),
    CONSTRAINT `fk_nexa_currency_history_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_currency_history_json` CHECK (JSON_VALID(`currencies_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `nexa_tenant_currency_profile` (`tenant_id`, `service_id`, `base_currency`, `default_currency`)
SELECT `tenant_id`, `service_id`, 'USD', 'USD' FROM `nexa_tenant_service`;

INSERT IGNORE INTO `nexa_tenant_currency_rate` (`tenant_id`, `service_id`, `currency_code`, `rate`, `position`)
SELECT `tenant_id`, `service_id`, 'USD', 1, 0 FROM `nexa_tenant_service`;
