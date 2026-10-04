-- Phase 5 governed behavior-event retention, legal holds and bounded purge indexes.

CREATE TABLE IF NOT EXISTS `nexa_event_retention_policy` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `identified_retention_days` SMALLINT UNSIGNED NOT NULL DEFAULT 730,
    `anonymous_retention_days` SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    `replay_retention_days` SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    `legal_hold` TINYINT(1) NOT NULL DEFAULT 0,
    `legal_hold_reason` VARCHAR(500) NULL,
    `updated_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_event_retention_scope` (`tenant_id`,`service_id`),
    CONSTRAINT `fk_nexa_event_retention_tenant_service`
        FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_event_retention_identified` CHECK (`identified_retention_days` BETWEEN 90 AND 2555),
    CONSTRAINT `chk_nexa_event_retention_anonymous` CHECK (`anonymous_retention_days` BETWEEN 30 AND 730),
    CONSTRAINT `chk_nexa_event_retention_replay` CHECK (`replay_retention_days` BETWEEN 30 AND 365),
    CONSTRAINT `chk_nexa_event_retention_hold_reason` CHECK (`legal_hold` = 0 OR LENGTH(TRIM(`legal_hold_reason`)) >= 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `nexa_behavior_event`
    ADD INDEX IF NOT EXISTS `idx_nexa_behavior_retention`
        (`tenant_id`,`service_id`,`contact_id`,`occurred_at`,`id`);

ALTER TABLE `nexa_behavior_event_replay`
    ADD INDEX IF NOT EXISTS `idx_nexa_behavior_replay_retention`
        (`tenant_id`,`service_id`,`requested_at`,`id`);

ALTER TABLE `nexa_visitor_identity`
    ADD INDEX IF NOT EXISTS `idx_nexa_visitor_retention`
        (`tenant_id`,`service_id`,`last_seen_at`,`id`);
