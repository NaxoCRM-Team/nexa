-- M08 public form runtime evidence and performance events. Native
-- lead_capture_log_record remains the canonical submitted payload log.

CREATE TABLE IF NOT EXISTS `nexa_form_event` (
    `id` CHAR(36) NOT NULL,
    `tenant_id` CHAR(36) NOT NULL,
    `service_id` CHAR(36) NOT NULL,
    `lead_capture_id` VARCHAR(17) NOT NULL,
    `submission_key` CHAR(36) NOT NULL,
    `event_type` VARCHAR(24) NOT NULL,
    `target_type` VARCHAR(64) NULL,
    `target_id` VARCHAR(17) NULL,
    `visitor_id` VARCHAR(64) NULL,
    `source_page` VARCHAR(1000) NULL,
    `referrer` VARCHAR(1000) NULL,
    `user_agent_hash` CHAR(64) NULL,
    `consent_purpose_id` CHAR(36) NULL,
    `consent_channel` VARCHAR(24) NULL,
    `consent_status` VARCHAR(24) NULL,
    `policy_version` VARCHAR(40) NULL,
    `form_version` INT UNSIGNED NOT NULL DEFAULT 0,
    `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nexa_form_event_scope_id` (`tenant_id`, `service_id`, `id`),
    UNIQUE KEY `uq_nexa_form_event_submission_type` (`tenant_id`, `service_id`, `submission_key`, `event_type`),
    KEY `idx_nexa_form_event_form` (`tenant_id`, `service_id`, `lead_capture_id`, `event_type`, `occurred_at`),
    KEY `idx_nexa_form_event_target` (`tenant_id`, `service_id`, `target_type`, `target_id`),
    CONSTRAINT `fk_nexa_form_event_profile`
        FOREIGN KEY (`tenant_id`, `service_id`, `lead_capture_id`)
        REFERENCES `nexa_form_profile` (`tenant_id`, `service_id`, `lead_capture_id`) ON DELETE CASCADE,
    CONSTRAINT `chk_nexa_form_event_type`
        CHECK (`event_type` IN ('view', 'submission')),
    CONSTRAINT `chk_nexa_form_event_consent_channel`
        CHECK (`consent_channel` IS NULL OR `consent_channel` IN ('email', 'phone', 'sms', 'whatsapp', 'linkedin', 'postal', 'live_chat')),
    CONSTRAINT `chk_nexa_form_event_consent_status`
        CHECK (`consent_status` IS NULL OR `consent_status` IN ('granted', 'denied'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
