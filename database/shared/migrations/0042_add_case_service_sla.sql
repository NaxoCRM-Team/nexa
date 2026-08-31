-- Extend native Cases with tenant-owned service policy and commercial context.
CREATE TABLE IF NOT EXISTS `nexa_case_sla_policy` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `priority` VARCHAR(30) NULL,
    `category` VARCHAR(80) NULL,
    `first_response_minutes` INT UNSIGNED NOT NULL,
    `resolution_minutes` INT UNSIGNED NOT NULL,
    `escalation_minutes` INT UNSIGNED NULL,
    `pause_statuses_json` LONGTEXT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_nexa_case_sla_match` (`tenant_id`, `service_id`, `is_active`, `priority`, `category`),
    CONSTRAINT `fk_nexa_case_sla_service` FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_case_sla_response` CHECK (`first_response_minutes` > 0),
    CONSTRAINT `chk_nexa_case_sla_resolution` CHECK (`resolution_minutes` > 0),
    CONSTRAINT `chk_nexa_case_sla_pauses` CHECK (`pause_statuses_json` IS NULL OR JSON_VALID(`pause_statuses_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `case`
    ADD COLUMN IF NOT EXISTS `category` VARCHAR(80) NULL AFTER `type`,
    ADD COLUMN IF NOT EXISTS `sla_policy_id` CHAR(36) NULL AFTER `category`,
    ADD COLUMN IF NOT EXISTS `sla_status` VARCHAR(30) NOT NULL DEFAULT 'Not Started' AFTER `sla_policy_id`,
    ADD COLUMN IF NOT EXISTS `first_response_due_at` DATETIME NULL AFTER `sla_status`,
    ADD COLUMN IF NOT EXISTS `resolution_due_at` DATETIME NULL AFTER `first_response_due_at`,
    ADD COLUMN IF NOT EXISTS `first_responded_at` DATETIME NULL AFTER `resolution_due_at`,
    ADD COLUMN IF NOT EXISTS `resolved_at` DATETIME NULL AFTER `first_responded_at`,
    ADD COLUMN IF NOT EXISTS `sla_paused_at` DATETIME NULL AFTER `resolved_at`,
    ADD COLUMN IF NOT EXISTS `sla_paused_seconds` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `sla_paused_at`,
    ADD COLUMN IF NOT EXISTS `escalation_level` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `sla_paused_seconds`,
    ADD COLUMN IF NOT EXISTS `escalated_at` DATETIME NULL AFTER `escalation_level`,
    ADD COLUMN IF NOT EXISTS `escalation_reason` VARCHAR(255) NULL AFTER `escalated_at`,
    ADD COLUMN IF NOT EXISTS `opportunity_id` VARCHAR(17) NULL AFTER `escalation_reason`,
    ADD COLUMN IF NOT EXISTS `nexa_quote_id` CHAR(36) NULL AFTER `opportunity_id`,
    ADD INDEX IF NOT EXISTS `idx_case_sla_due` (`tenant_id`, `service_id`, `sla_status`, `resolution_due_at`),
    ADD INDEX IF NOT EXISTS `idx_case_sla_policy` (`sla_policy_id`),
    ADD INDEX IF NOT EXISTS `idx_case_opportunity` (`opportunity_id`),
    ADD INDEX IF NOT EXISTS `idx_case_quote` (`nexa_quote_id`);

-- Preserve existing Case assignments created through the retired multi-owner field.
UPDATE `case` c
JOIN (
    SELECT eu.entity_id, MIN(eu.user_id) AS user_id
    FROM entity_user eu
    WHERE eu.entity_type = 'Case' AND eu.deleted = 0
    GROUP BY eu.entity_id
) owner_link ON owner_link.entity_id = c.id
SET c.assigned_user_id = owner_link.user_id
WHERE c.assigned_user_id IS NULL;

-- Every existing tenant gets sensible defaults; tenant admins can edit them later.
INSERT INTO `nexa_case_sla_policy`
    (`id`, `tenant_id`, `service_id`, `name`, `priority`, `first_response_minutes`, `resolution_minutes`, `escalation_minutes`, `pause_statuses_json`, `is_default`)
SELECT LOWER(CONCAT(
        SUBSTR(MD5(CONCAT(ts.tenant_id, ts.service_id, p.priority, 'case-sla')), 1, 8), '-',
        SUBSTR(MD5(CONCAT(ts.tenant_id, ts.service_id, p.priority, 'case-sla')), 9, 4), '-4',
        SUBSTR(MD5(CONCAT(ts.tenant_id, ts.service_id, p.priority, 'case-sla')), 14, 3), '-8',
        SUBSTR(MD5(CONCAT(ts.tenant_id, ts.service_id, p.priority, 'case-sla')), 18, 3), '-',
        SUBSTR(MD5(CONCAT(ts.tenant_id, ts.service_id, p.priority, 'case-sla')), 21, 12)
    )), ts.tenant_id, ts.service_id, CONCAT(p.priority, ' support'), p.priority,
    p.response_minutes, p.resolution_minutes, p.escalation_minutes, '["Pending"]', p.is_default
FROM nexa_tenant_service ts
JOIN (
    SELECT 'Urgent' priority, 60 response_minutes, 480 resolution_minutes, 240 escalation_minutes, 0 is_default
    UNION ALL SELECT 'High', 240, 1440, 720, 0
    UNION ALL SELECT 'Normal', 480, 2880, 1440, 1
    UNION ALL SELECT 'Low', 1440, 7200, 2880, 0
) p
WHERE ts.status = 'active'
ON DUPLICATE KEY UPDATE `modified_at` = `modified_at`;

-- Put pre-existing open Cases onto the matching policy without rewriting their lifecycle.
UPDATE `case` c
JOIN `nexa_case_sla_policy` p
    ON p.tenant_id = c.tenant_id
    AND p.service_id = c.service_id
    AND p.is_active = 1
    AND p.priority = c.priority
    AND p.category IS NULL
SET c.sla_policy_id = p.id,
    c.sla_status = CASE
        WHEN c.status IN ('Closed','Rejected','Duplicate') THEN 'Met'
        WHEN c.status = 'Pending' THEN 'Paused'
        ELSE 'Running'
    END,
    c.first_response_due_at = DATE_ADD(COALESCE(c.created_at, CURRENT_TIMESTAMP), INTERVAL p.first_response_minutes MINUTE),
    c.resolution_due_at = DATE_ADD(COALESCE(c.created_at, CURRENT_TIMESTAMP), INTERVAL p.resolution_minutes MINUTE),
    c.sla_paused_at = CASE WHEN c.status = 'Pending' THEN CURRENT_TIMESTAMP ELSE NULL END,
    c.resolved_at = CASE WHEN c.status IN ('Closed','Rejected','Duplicate') THEN COALESCE(c.modified_at, CURRENT_TIMESTAMP) ELSE NULL END
WHERE c.sla_policy_id IS NULL;
