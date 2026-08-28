-- Add the tenant-owned sales configuration used around native Opportunity records.
ALTER TABLE `opportunity`
    ADD COLUMN IF NOT EXISTS `pipeline_id` CHAR(36) NULL AFTER `stage`,
    ADD COLUMN IF NOT EXISTS `forecast_category` VARCHAR(40) NOT NULL DEFAULT 'Pipeline' AFTER `probability`,
    ADD COLUMN IF NOT EXISTS `expected_revenue` DECIMAL(18,2) NULL AFTER `amount_currency`,
    ADD COLUMN IF NOT EXISTS `next_step` VARCHAR(255) NULL AFTER `expected_revenue`,
    ADD COLUMN IF NOT EXISTS `loss_reason` VARCHAR(255) NULL AFTER `next_step`,
    ADD INDEX IF NOT EXISTS `idx_opportunity_tenant_pipeline` (`tenant_id`, `service_id`, `pipeline_id`, `stage`, `deleted`),
    ADD INDEX IF NOT EXISTS `idx_opportunity_tenant_forecast` (`tenant_id`, `service_id`, `forecast_category`, `close_date`, `deleted`);

CREATE TABLE IF NOT EXISTS `nexa_sales_pipeline` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `pipeline_key` VARCHAR(80) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_sales_pipeline_key` (`tenant_id`, `service_id`, `pipeline_key`),
    KEY `idx_nexa_sales_pipeline_default` (`tenant_id`, `service_id`, `is_default`, `is_active`),
    CONSTRAINT `fk_nexa_sales_pipeline_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_sales_stage` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `pipeline_id` CHAR(36) NOT NULL,
    `stage_key` VARCHAR(80) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `probability` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `forecast_category` VARCHAR(40) NOT NULL DEFAULT 'Pipeline',
    `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `required_fields_json` LONGTEXT NULL,
    `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
    `is_won` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_sales_stage_key` (`tenant_id`, `service_id`, `pipeline_id`, `stage_key`),
    KEY `idx_nexa_sales_stage_order` (`tenant_id`, `service_id`, `pipeline_id`, `position`, `is_active`),
    CONSTRAINT `fk_nexa_sales_stage_pipeline` FOREIGN KEY (`pipeline_id`)
        REFERENCES `nexa_sales_pipeline` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_sales_stage_probability` CHECK (`probability` <= 100),
    CONSTRAINT `chk_nexa_sales_stage_required` CHECK (`required_fields_json` IS NULL OR JSON_VALID(`required_fields_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_product` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `sku` VARCHAR(100) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `currency` CHAR(3) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_product_sku` (`tenant_id`, `service_id`, `sku`),
    KEY `idx_nexa_product_active` (`tenant_id`, `service_id`, `is_active`, `name`),
    CONSTRAINT `fk_nexa_product_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_opportunity_line_item` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `opportunity_id` VARCHAR(17) NOT NULL,
    `product_id` CHAR(36) NULL,
    `name` VARCHAR(200) NOT NULL,
    `quantity` DECIMAL(12,3) NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `discount_percent` DECIMAL(6,3) NOT NULL DEFAULT 0,
    `currency` CHAR(3) NOT NULL,
    `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_nexa_line_opportunity` (`tenant_id`, `service_id`, `opportunity_id`, `position`),
    CONSTRAINT `fk_nexa_line_product` FOREIGN KEY (`product_id`) REFERENCES `nexa_product` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_nexa_line_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_quote` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `opportunity_id` VARCHAR(17) NOT NULL,
    `quote_number` VARCHAR(80) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `status` VARCHAR(40) NOT NULL DEFAULT 'Draft',
    `currency` CHAR(3) NOT NULL,
    `subtotal` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `discount_total` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `tax_total` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `grand_total` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `valid_until` DATE NULL,
    `created_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_quote_number` (`tenant_id`, `service_id`, `quote_number`),
    KEY `idx_nexa_quote_opportunity` (`tenant_id`, `service_id`, `opportunity_id`, `status`),
    CONSTRAINT `fk_nexa_quote_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_opportunity_revenue_history` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `opportunity_id` VARCHAR(17) NOT NULL,
    `account_id` VARCHAR(17) NULL,
    `amount` DECIMAL(18,2) NULL,
    `currency` CHAR(3) NULL,
    `expected_revenue` DECIMAL(18,2) NULL,
    `stage` VARCHAR(160) NULL,
    `forecast_category` VARCHAR(40) NULL,
    `changed_by_id` VARCHAR(17) NULL,
    `changed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_nexa_revenue_opportunity` (`tenant_id`, `service_id`, `opportunity_id`, `changed_at`),
    KEY `idx_nexa_revenue_account` (`tenant_id`, `service_id`, `account_id`, `changed_at`),
    CONSTRAINT `fk_nexa_revenue_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
