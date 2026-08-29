-- Complete tenant-scoped administration and commercial lifecycle around native Opportunities.
ALTER TABLE `nexa_sales_pipeline`
    ADD COLUMN IF NOT EXISTS `description` TEXT NULL AFTER `name`,
    ADD COLUMN IF NOT EXISTS `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `description`,
    ADD COLUMN IF NOT EXISTS `modified_by_id` VARCHAR(17) NULL AFTER `created_by_id`,
    ADD COLUMN IF NOT EXISTS `archived_at` DATETIME(6) NULL AFTER `modified_at`,
    ADD INDEX IF NOT EXISTS `idx_nexa_sales_pipeline_order` (`tenant_id`, `service_id`, `is_active`, `position`, `name`);

ALTER TABLE `nexa_sales_stage`
    ADD COLUMN IF NOT EXISTS `modified_by_id` VARCHAR(17) NULL AFTER `is_active`,
    ADD COLUMN IF NOT EXISTS `archived_at` DATETIME(6) NULL AFTER `modified_at`;

ALTER TABLE `nexa_product`
    ADD COLUMN IF NOT EXISTS `unit` VARCHAR(40) NOT NULL DEFAULT 'each' AFTER `description`,
    ADD COLUMN IF NOT EXISTS `tax_percent` DECIMAL(6,3) NOT NULL DEFAULT 0 AFTER `currency`,
    ADD COLUMN IF NOT EXISTS `modified_by_id` VARCHAR(17) NULL AFTER `created_by_id`,
    ADD COLUMN IF NOT EXISTS `archived_at` DATETIME(6) NULL AFTER `modified_at`;

ALTER TABLE `nexa_opportunity_line_item`
    ADD COLUMN IF NOT EXISTS `tax_percent` DECIMAL(6,3) NOT NULL DEFAULT 0 AFTER `discount_percent`,
    ADD COLUMN IF NOT EXISTS `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) AFTER `created_at`;

ALTER TABLE `nexa_quote`
    ADD COLUMN IF NOT EXISTS `version_number` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `quote_number`,
    ADD COLUMN IF NOT EXISTS `parent_quote_id` CHAR(36) NULL AFTER `version_number`,
    ADD COLUMN IF NOT EXISTS `modified_by_id` VARCHAR(17) NULL AFTER `created_by_id`,
    ADD COLUMN IF NOT EXISTS `sent_at` DATETIME(6) NULL AFTER `modified_at`,
    ADD COLUMN IF NOT EXISTS `accepted_at` DATETIME(6) NULL AFTER `sent_at`,
    ADD COLUMN IF NOT EXISTS `rejected_at` DATETIME(6) NULL AFTER `accepted_at`,
    ADD INDEX IF NOT EXISTS `idx_nexa_quote_status_date` (`tenant_id`, `service_id`, `status`, `modified_at`);

CREATE TABLE IF NOT EXISTS `nexa_quote_status_history` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `quote_id` CHAR(36) NOT NULL,
    `from_status` VARCHAR(40) NULL,
    `to_status` VARCHAR(40) NOT NULL,
    `comment` TEXT NULL,
    `changed_by_id` VARCHAR(17) NULL,
    `changed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_nexa_quote_history` (`tenant_id`, `service_id`, `quote_id`, `changed_at`),
    CONSTRAINT `fk_nexa_quote_history_quote` FOREIGN KEY (`quote_id`)
        REFERENCES `nexa_quote` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_quote_history_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
