-- Tenant-owned cookie banner configuration and append-only browser receipts.

CREATE TABLE IF NOT EXISTS `nexa_cookie_banner` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `public_key` CHAR(48) NOT NULL,
    `name` VARCHAR(120) NOT NULL DEFAULT 'Website cookie banner',
    `policy_version` VARCHAR(40) NOT NULL DEFAULT '1.0',
    `locale` VARCHAR(12) NOT NULL DEFAULT 'en',
    `region_mode` VARCHAR(24) NOT NULL DEFAULT 'global',
    `regions_json` JSON NULL,
    `position` VARCHAR(24) NOT NULL DEFAULT 'bottom',
    `privacy_notice_url` VARCHAR(500) NULL,
    `heading` VARCHAR(160) NOT NULL DEFAULT 'Your privacy choices',
    `message` VARCHAR(1000) NOT NULL,
    `primary_color` CHAR(7) NOT NULL DEFAULT '#087F6D',
    `background_color` CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    `text_color` CHAR(7) NOT NULL DEFAULT '#172B26',
    `show_reject` TINYINT(1) NOT NULL DEFAULT 1,
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `published_at` DATETIME(6) NULL,
    `created_by_id` VARCHAR(17) NULL,
    `modified_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_cookie_banner_scope` (`tenant_id`, `service_id`),
    UNIQUE KEY `uq_nexa_cookie_banner_public_key` (`public_key`),
    CONSTRAINT `fk_nexa_cookie_banner_tenant_service`
        FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_cookie_banner_region_mode`
        CHECK (`region_mode` IN ('global', 'eu_uk', 'custom')),
    CONSTRAINT `chk_nexa_cookie_banner_position`
        CHECK (`position` IN ('bottom', 'bottom_left', 'bottom_right'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_cookie_category` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `banner_id` CHAR(36) NOT NULL,
    `category_key` VARCHAR(64) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(500) NULL,
    `is_essential` TINYINT(1) NOT NULL DEFAULT 0,
    `default_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_cookie_category_scope_id` (`tenant_id`, `service_id`, `id`),
    UNIQUE KEY `uq_nexa_cookie_category_key` (`tenant_id`, `service_id`, `banner_id`, `category_key`),
    KEY `idx_nexa_cookie_category_active` (`tenant_id`, `service_id`, `banner_id`, `is_active`, `position`),
    CONSTRAINT `fk_nexa_cookie_category_banner`
        FOREIGN KEY (`tenant_id`, `service_id`, `banner_id`)
        REFERENCES `nexa_cookie_banner` (`tenant_id`, `service_id`, `id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_cookie_receipt` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `banner_id` CHAR(36) NOT NULL,
    `receipt_key` CHAR(36) NOT NULL,
    `visitor_id` CHAR(36) NOT NULL,
    `choice` VARCHAR(24) NOT NULL,
    `policy_version` VARCHAR(40) NOT NULL,
    `categories_json` JSON NOT NULL,
    `configuration_hash` CHAR(64) NOT NULL,
    `page_url` VARCHAR(1000) NULL,
    `referrer_url` VARCHAR(1000) NULL,
    `locale` VARCHAR(12) NULL,
    `region_code` VARCHAR(12) NULL,
    `global_privacy_control` TINYINT(1) NOT NULL DEFAULT 0,
    `user_agent_hash` CHAR(64) NULL,
    `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_cookie_receipt_scope_id` (`tenant_id`, `service_id`, `id`),
    UNIQUE KEY `uq_nexa_cookie_receipt_key` (`tenant_id`, `service_id`, `receipt_key`),
    KEY `idx_nexa_cookie_receipt_visitor` (`tenant_id`, `service_id`, `visitor_id`, `occurred_at`),
    KEY `idx_nexa_cookie_receipt_banner` (`tenant_id`, `service_id`, `banner_id`, `occurred_at`),
    CONSTRAINT `fk_nexa_cookie_receipt_banner`
        FOREIGN KEY (`tenant_id`, `service_id`, `banner_id`)
        REFERENCES `nexa_cookie_banner` (`tenant_id`, `service_id`, `id`),
    CONSTRAINT `chk_nexa_cookie_receipt_choice`
        CHECK (`choice` IN ('accept_all', 'reject_optional', 'custom'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
