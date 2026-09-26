-- Phase 4 consent governance extends native Contact marketing status and
-- EspoCRM opt-out fields. It does not introduce a second customer record.

CREATE TABLE IF NOT EXISTS `nexa_consent_purpose` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `purpose_key` VARCHAR(64) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(1000) NULL,
    `default_legal_basis` VARCHAR(120) NULL,
    `channels_json` JSON NOT NULL,
    `privacy_notice_url` VARCHAR(500) NULL,
    `policy_version` VARCHAR(40) NOT NULL DEFAULT '1.0',
    `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_id` VARCHAR(17) NULL,
    `modified_by_id` VARCHAR(17) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `modified_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_consent_purpose_scope_id` (`tenant_id`, `service_id`, `id`),
    UNIQUE KEY `uq_nexa_consent_purpose_key` (`tenant_id`, `service_id`, `purpose_key`),
    KEY `idx_nexa_consent_purpose_active` (`tenant_id`, `service_id`, `is_active`, `position`),
    CONSTRAINT `fk_nexa_consent_purpose_tenant_service`
        FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_consent_event` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `contact_id` VARCHAR(17) NOT NULL,
    `purpose_id` CHAR(36) NOT NULL,
    `channel` VARCHAR(24) NOT NULL,
    `status` VARCHAR(24) NOT NULL,
    `legal_basis` VARCHAR(120) NULL,
    `source` VARCHAR(40) NOT NULL,
    `policy_version` VARCHAR(40) NOT NULL,
    `privacy_notice_url` VARCHAR(500) NULL,
    `evidence_note` VARCHAR(1000) NULL,
    `evidence_json` JSON NULL,
    `actor_type` VARCHAR(24) NOT NULL DEFAULT 'user',
    `actor_id` VARCHAR(36) NULL,
    `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `expires_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_consent_event_scope_id` (`tenant_id`, `service_id`, `id`),
    KEY `idx_nexa_consent_event_contact` (`tenant_id`, `service_id`, `contact_id`, `occurred_at`),
    KEY `idx_nexa_consent_event_purpose` (`tenant_id`, `service_id`, `purpose_id`, `channel`, `occurred_at`),
    CONSTRAINT `fk_nexa_consent_event_tenant_service`
        FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_consent_event_purpose`
        FOREIGN KEY (`tenant_id`, `service_id`, `purpose_id`)
        REFERENCES `nexa_consent_purpose` (`tenant_id`, `service_id`, `id`),
    CONSTRAINT `chk_nexa_consent_event_channel`
        CHECK (`channel` IN ('email', 'phone', 'sms', 'whatsapp', 'linkedin', 'postal', 'live_chat')),
    CONSTRAINT `chk_nexa_consent_event_status`
        CHECK (`status` IN ('granted', 'denied', 'withdrawn', 'not_required')),
    CONSTRAINT `chk_nexa_consent_event_expiry`
        CHECK (`expires_at` IS NULL OR `expires_at` >= `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nexa_consent_state` (
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `contact_id` VARCHAR(17) NOT NULL,
    `purpose_id` CHAR(36) NOT NULL,
    `channel` VARCHAR(24) NOT NULL,
    `status` VARCHAR(24) NOT NULL,
    `legal_basis` VARCHAR(120) NULL,
    `policy_version` VARCHAR(40) NOT NULL,
    `source_event_id` CHAR(36) NOT NULL,
    `effective_at` DATETIME(6) NOT NULL,
    `expires_at` DATETIME(6) NULL,
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`tenant_id`, `service_id`, `contact_id`, `purpose_id`, `channel`),
    KEY `idx_nexa_consent_state_eligibility` (`tenant_id`, `service_id`, `channel`, `status`, `expires_at`),
    CONSTRAINT `fk_nexa_consent_state_tenant_service`
        FOREIGN KEY (`tenant_id`, `service_id`)
        REFERENCES `nexa_tenant_service` (`tenant_id`, `service_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nexa_consent_state_purpose`
        FOREIGN KEY (`tenant_id`, `service_id`, `purpose_id`)
        REFERENCES `nexa_consent_purpose` (`tenant_id`, `service_id`, `id`),
    CONSTRAINT `fk_nexa_consent_state_event`
        FOREIGN KEY (`tenant_id`, `service_id`, `source_event_id`)
        REFERENCES `nexa_consent_event` (`tenant_id`, `service_id`, `id`),
    CONSTRAINT `chk_nexa_consent_state_channel`
        CHECK (`channel` IN ('email', 'phone', 'sms', 'whatsapp', 'linkedin', 'postal', 'live_chat')),
    CONSTRAINT `chk_nexa_consent_state_status`
        CHECK (`status` IN ('granted', 'denied', 'withdrawn', 'not_required'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

