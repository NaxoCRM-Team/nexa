ALTER TABLE nexa_messaging_connection
 ADD COLUMN IF NOT EXISTS dispatch_enabled TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE nexa_messaging_outbox
 DROP CONSTRAINT IF EXISTS chk_messaging_outbox_held,
 ADD COLUMN IF NOT EXISTS conversation_id VARCHAR(17) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS purpose_id CHAR(36) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS consent_event_id CHAR(36) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS message_encrypted MEDIUMTEXT DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS version_number BIGINT NOT NULL DEFAULT 1,
 ADD COLUMN IF NOT EXISTS attempt_id CHAR(36) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS attempts INT NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS provider_message_id CHAR(34) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(20) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS next_attempt_at DATETIME DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS submitted_at DATETIME DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS inspected_at DATETIME DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS last_error VARCHAR(40) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS modified_at DATETIME DEFAULT NULL,
 ADD INDEX IF NOT EXISTS idx_sms_dispatch(tenant_id,service_id,status,next_attempt_at,created_at),
 ADD INDEX IF NOT EXISTS idx_sms_outgoing_thread(tenant_id,service_id,conversation_id,created_at),
 ADD UNIQUE INDEX IF NOT EXISTS uq_sms_provider_message(tenant_id,service_id,connection_id,provider_message_id),
 ADD UNIQUE INDEX IF NOT EXISTS uq_sms_outbox_identity(tenant_id,service_id,id),
 ADD CONSTRAINT chk_sms_outbox_status CHECK(status IN ('held','queued','submitting','submitted','uncertain','failed','cancelled','blocked')),
 ADD CONSTRAINT fk_sms_outbox_conversation FOREIGN KEY(tenant_id,service_id,conversation_id)
 REFERENCES nexa_messaging_conversation(tenant_id,service_id,id);

CREATE TABLE IF NOT EXISTS nexa_sms_attempt (
 id CHAR(36) NOT NULL PRIMARY KEY, tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL,
 outbox_id CHAR(36) NOT NULL, connection_id CHAR(36) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'submitting', provider_message_id CHAR(34) DEFAULT NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME DEFAULT NULL,
 UNIQUE KEY uq_sms_attempt_scope(tenant_id,service_id,id),
 KEY idx_sms_attempt_quota(tenant_id,service_id,started_at),
 KEY idx_sms_attempt_outbox(tenant_id,service_id,outbox_id),
 CONSTRAINT fk_sms_attempt_connection FOREIGN KEY(tenant_id,service_id,connection_id)
 REFERENCES nexa_messaging_connection(tenant_id,service_id,id),
 CONSTRAINT fk_sms_attempt_outbox FOREIGN KEY(tenant_id,service_id,outbox_id)
 REFERENCES nexa_messaging_outbox(tenant_id,service_id,id),
 CONSTRAINT chk_sms_attempt_status CHECK(status IN ('submitting','accepted','rejected','uncertain'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nexa_messaging_receipt
 ADD COLUMN IF NOT EXISTS attempt_id CHAR(36) DEFAULT NULL,
 DROP CONSTRAINT IF EXISTS chk_messaging_receipt_disposition,
 ADD CONSTRAINT chk_messaging_receipt_disposition CHECK(disposition IN ('received','unmatched_status','applied_status'));
