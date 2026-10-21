ALTER TABLE nexa_messaging_connection
 ADD COLUMN IF NOT EXISTS messenger_app_id VARCHAR(32) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS messenger_app_secret TEXT DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS messenger_verify_hash CHAR(64) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS messenger_webhook_verified_at DATETIME DEFAULT NULL,
 DROP CONSTRAINT IF EXISTS chk_messaging_connection_channel,
 DROP CONSTRAINT IF EXISTS chk_messaging_verified_proof,
 ADD CONSTRAINT chk_messaging_connection_channel CHECK(channel IN ('sms','whatsapp','messenger')),
 ADD CONSTRAINT chk_messaging_provider_channel CHECK((provider='twilio' AND channel IN ('sms','whatsapp')) OR (provider='meta' AND channel='messenger' AND messenger_app_id IS NOT NULL AND messenger_app_secret IS NOT NULL AND messenger_verify_hash IS NOT NULL)),
 ADD CONSTRAINT chk_messaging_verified_proof CHECK(status<>'verified' OR (verified_at IS NOT NULL AND provider_resource_sid IS NOT NULL AND ((provider='twilio' AND channel='sms' AND provider_resource_sid REGEXP '^PN[0-9a-fA-F]{32}$') OR (provider='twilio' AND channel='whatsapp' AND provider_resource_sid REGEXP '^XE[0-9a-fA-F]{32}$') OR (provider='meta' AND channel='messenger' AND provider_resource_sid=sender AND sender REGEXP '^[0-9]{1,32}$'))));

CREATE TABLE IF NOT EXISTS nexa_messenger_event (
 id CHAR(36) PRIMARY KEY,tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,
 connection_id CHAR(36) NOT NULL,conversation_id VARCHAR(17) NOT NULL,
 event_key CHAR(64) NOT NULL,payload_hash CHAR(64) NOT NULL,payload_encrypted MEDIUMTEXT NOT NULL,is_opt_out TINYINT(1) NOT NULL DEFAULT 0,
 provider_at DATETIME(3) NOT NULL,received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 UNIQUE KEY uq_messenger_event(tenant_id,service_id,connection_id,event_key),
 KEY idx_messenger_history(tenant_id,service_id,conversation_id,provider_at,id),
 KEY idx_messenger_opt_out(tenant_id,service_id,conversation_id,is_opt_out),
 CONSTRAINT fk_messenger_event_connection FOREIGN KEY(tenant_id,service_id,connection_id) REFERENCES nexa_messaging_connection(tenant_id,service_id,id),
 CONSTRAINT fk_messenger_event_conversation FOREIGN KEY(tenant_id,service_id,conversation_id) REFERENCES nexa_messaging_conversation(tenant_id,service_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nexa_messenger_reply (
 id CHAR(36) PRIMARY KEY,tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,
 connection_id CHAR(36) NOT NULL,conversation_id VARCHAR(17) NOT NULL,created_by_id VARCHAR(17) NOT NULL,
 request_key CHAR(36) NOT NULL,intent_hash CHAR(64) NOT NULL,body_encrypted MEDIUMTEXT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'submitting',provider_message_id VARCHAR(255) DEFAULT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_messenger_reply_request(tenant_id,service_id,request_key),
 KEY idx_messenger_reply_quota(tenant_id,service_id,created_at),
 KEY idx_messenger_reply_history(tenant_id,service_id,conversation_id,created_at,id),
 CONSTRAINT chk_messenger_reply_state CHECK(status IN ('submitting','accepted','failed','uncertain')),
 CONSTRAINT fk_messenger_reply_connection FOREIGN KEY(tenant_id,service_id,connection_id) REFERENCES nexa_messaging_connection(tenant_id,service_id,id),
 CONSTRAINT fk_messenger_reply_conversation FOREIGN KEY(tenant_id,service_id,conversation_id) REFERENCES nexa_messaging_conversation(tenant_id,service_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
