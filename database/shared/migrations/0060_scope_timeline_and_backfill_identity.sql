-- Phase 5 canonical timeline service ownership and behavior-event projection support.

ALTER TABLE `nexa_timeline_event`
    ADD COLUMN `service_id` CHAR(36) NULL AFTER `tenant_id`;

UPDATE `nexa_timeline_event` t
INNER JOIN `nexa_behavior_event` b
    ON b.tenant_id=t.tenant_id AND b.id=t.source_entity_id AND t.source_entity_type='BehaviorEvent'
SET t.service_id=b.service_id
WHERE t.service_id IS NULL;

UPDATE `nexa_timeline_event` t
INNER JOIN `contact` c ON c.tenant_id=t.tenant_id AND c.id=t.contact_id
SET t.service_id=c.service_id
WHERE t.service_id IS NULL;

UPDATE `nexa_timeline_event` t
INNER JOIN `account` a ON a.tenant_id=t.tenant_id AND a.id=t.account_id
SET t.service_id=a.service_id
WHERE t.service_id IS NULL;

ALTER TABLE `nexa_timeline_event`
    MODIFY COLUMN `service_id` CHAR(36) NOT NULL,
    DROP INDEX `uq_nexa_timeline_source`,
    DROP INDEX `idx_nexa_timeline_contact`,
    DROP INDEX `idx_nexa_timeline_account`,
    DROP INDEX `idx_nexa_timeline_correlation`,
    ADD UNIQUE KEY `uq_nexa_timeline_source` (`tenant_id`,`service_id`,`source_entity_type`,`source_entity_id`,`event_type`),
    ADD KEY `idx_nexa_timeline_contact` (`tenant_id`,`service_id`,`contact_id`,`source_occurred_at`),
    ADD KEY `idx_nexa_timeline_account` (`tenant_id`,`service_id`,`account_id`,`source_occurred_at`),
    ADD KEY `idx_nexa_timeline_correlation` (`tenant_id`,`service_id`,`correlation_id`),
    ADD CONSTRAINT `fk_nexa_timeline_tenant_service`
        FOREIGN KEY (`tenant_id`,`service_id`) REFERENCES `nexa_tenant_service` (`tenant_id`,`service_id`) ON DELETE CASCADE;
