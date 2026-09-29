-- M11 trusted customer-event foundation. Existing identity, timeline and outbox remain canonical.
CREATE TABLE IF NOT EXISTS `nexa_visitor_identity` (
    `id` CHAR(36) NOT NULL, `tenant_id` CHAR(36) NOT NULL, `service_id` CHAR(36) NOT NULL,
    `visitor_key_hash` CHAR(64) NOT NULL, `contact_id` VARCHAR(17) NULL,
    `first_seen_at` DATETIME(6) NOT NULL, `last_seen_at` DATETIME(6) NOT NULL, `identified_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_visitor_scope_key` (`tenant_id`,`service_id`,`visitor_key_hash`),
    KEY `idx_nexa_visitor_contact` (`tenant_id`,`service_id`,`contact_id`),
    CONSTRAINT `fk_nexa_visitor_tenant_service` FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `nexa_behavior_event` (
    `id` CHAR(36) NOT NULL, `tenant_id` CHAR(36) NOT NULL, `service_id` CHAR(36) NOT NULL,
    `event_type` VARCHAR(128) NOT NULL, `event_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `source` VARCHAR(64) NOT NULL, `idempotency_key` VARCHAR(191) NOT NULL, `correlation_id` CHAR(36) NULL,
    `visitor_identity_id` CHAR(36) NULL, `contact_id` VARCHAR(17) NULL, `account_id` VARCHAR(17) NULL,
    `session_key_hash` CHAR(64) NULL, `page_url` VARCHAR(2048) NULL, `referrer_url` VARCHAR(2048) NULL,
    `properties_json` JSON NOT NULL, `consent_json` JSON NOT NULL,
    `occurred_at` DATETIME(6) NOT NULL, `received_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_behavior_idempotency` (`tenant_id`,`service_id`,`source`,`idempotency_key`),
    KEY `idx_nexa_behavior_contact_time` (`tenant_id`,`service_id`,`contact_id`,`occurred_at`),
    KEY `idx_nexa_behavior_visitor_time` (`tenant_id`,`service_id`,`visitor_identity_id`,`occurred_at`),
    KEY `idx_nexa_behavior_type_time` (`tenant_id`,`service_id`,`event_type`,`occurred_at`),
    CONSTRAINT `fk_nexa_behavior_tenant_service` FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_behavior_visitor` FOREIGN KEY (`visitor_identity_id`) REFERENCES `nexa_visitor_identity` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
