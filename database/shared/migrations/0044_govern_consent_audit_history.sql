-- Consent evidence is immutable. Corrections append a replacement event and
-- voiding retains the original event, actor, timestamp and reason.

ALTER TABLE `nexa_consent_event`
    ADD COLUMN IF NOT EXISTS `supersedes_event_id` CHAR(36) NULL AFTER `expires_at`,
    ADD COLUMN IF NOT EXISTS `superseded_by_event_id` CHAR(36) NULL AFTER `supersedes_event_id`,
    ADD COLUMN IF NOT EXISTS `voided_at` DATETIME(6) NULL AFTER `superseded_by_event_id`,
    ADD COLUMN IF NOT EXISTS `voided_by_id` VARCHAR(36) NULL AFTER `voided_at`,
    ADD COLUMN IF NOT EXISTS `void_reason` VARCHAR(500) NULL AFTER `voided_by_id`,
    ADD KEY IF NOT EXISTS `idx_nexa_consent_event_active` (`tenant_id`, `service_id`, `voided_at`, `occurred_at`),
    ADD CONSTRAINT `fk_nexa_consent_event_supersedes`
        FOREIGN KEY (`tenant_id`, `service_id`, `supersedes_event_id`)
        REFERENCES `nexa_consent_event` (`tenant_id`, `service_id`, `id`),
    ADD CONSTRAINT `fk_nexa_consent_event_superseded_by`
        FOREIGN KEY (`tenant_id`, `service_id`, `superseded_by_event_id`)
        REFERENCES `nexa_consent_event` (`tenant_id`, `service_id`, `id`);
