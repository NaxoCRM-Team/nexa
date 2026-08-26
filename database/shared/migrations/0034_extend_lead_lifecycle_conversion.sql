-- Extend native Leads with Nexa qualification data and an immutable conversion ledger.
-- Native Lead, Contact, Account and Opportunity records remain authoritative.
ALTER TABLE `lead`
    ADD COLUMN IF NOT EXISTS `rating` VARCHAR(32) NULL AFTER `source`,
    ADD COLUMN IF NOT EXISTS `lead_score` INT NOT NULL DEFAULT 0 AFTER `rating`,
    ADD COLUMN IF NOT EXISTS `lifecycle_stage` VARCHAR(80) NULL AFTER `lead_score`,
    ADD COLUMN IF NOT EXISTS `marketing_status` VARCHAR(40) NOT NULL DEFAULT 'Non-Marketing' AFTER `lifecycle_stage`,
    ADD COLUMN IF NOT EXISTS `legal_basis` VARCHAR(120) NULL AFTER `marketing_status`,
    ADD COLUMN IF NOT EXISTS `last_website_visit_at` DATETIME NULL AFTER `legal_basis`,
    ADD COLUMN IF NOT EXISTS `first_activity_at` DATETIME NULL AFTER `last_website_visit_at`,
    ADD COLUMN IF NOT EXISTS `last_activity_at` DATETIME NULL AFTER `first_activity_at`,
    ADD COLUMN IF NOT EXISTS `last_email_interaction_at` DATETIME NULL AFTER `last_activity_at`,
    ADD COLUMN IF NOT EXISTS `next_activity_at` DATETIME NULL AFTER `last_email_interaction_at`,
    ADD COLUMN IF NOT EXISTS `converted_by_id` VARCHAR(17) NULL AFTER `converted_at`,
    ADD INDEX IF NOT EXISTS `idx_lead_tenant_lifecycle` (`tenant_id`, `service_id`, `lifecycle_stage`, `deleted`),
    ADD INDEX IF NOT EXISTS `idx_lead_tenant_rating` (`tenant_id`, `service_id`, `rating`, `deleted`);

CREATE TABLE IF NOT EXISTS `nexa_lead_conversion` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `lead_id` VARCHAR(17) NOT NULL,
    `contact_id` VARCHAR(17) NULL,
    `account_id` VARCHAR(17) NULL,
    `opportunity_id` VARCHAR(17) NULL,
    `converted_by_id` VARCHAR(17) NULL,
    `correlation_id` CHAR(36) NOT NULL,
    `snapshot_json` LONGTEXT NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_lead_conversion_scope` (`tenant_id`, `service_id`, `lead_id`),
    KEY `idx_nexa_lead_conversion_contact` (`tenant_id`, `service_id`, `contact_id`),
    KEY `idx_nexa_lead_conversion_account` (`tenant_id`, `service_id`, `account_id`),
    KEY `idx_nexa_lead_conversion_opportunity` (`tenant_id`, `service_id`, `opportunity_id`),
    CONSTRAINT `fk_nexa_lead_conversion_tenant_service`
        FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`)
        ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_lead_conversion_snapshot` CHECK (JSON_VALID(`snapshot_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
