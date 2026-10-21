ALTER TABLE nexa_messenger_event ADD COLUMN IF NOT EXISTS is_opt_in TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE nexa_messenger_reply
 ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(16) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS provider_sent_at DATETIME(3) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS delivered_at DATETIME(3) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS read_at DATETIME(3) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS attachment_id VARCHAR(17) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS media_type VARCHAR(16) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS media_sha256 CHAR(64) DEFAULT NULL,
 ADD UNIQUE INDEX IF NOT EXISTS uq_messenger_provider_reply(tenant_id,service_id,connection_id,provider_message_id),
 ADD CONSTRAINT chk_messenger_delivery CHECK(delivery_status IS NULL OR delivery_status IN ('accepted','delivered','read'));

CREATE TABLE IF NOT EXISTS nexa_messenger_receipt (
 id CHAR(36) PRIMARY KEY,tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,
 connection_id CHAR(36) NOT NULL,peer_hash CHAR(64) NOT NULL,event_key CHAR(64) NOT NULL,
 kind VARCHAR(16) NOT NULL,payload_encrypted MEDIUMTEXT NOT NULL,received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 UNIQUE KEY uq_messenger_receipt(tenant_id,service_id,connection_id,event_key),
 KEY idx_messenger_receipt_peer(tenant_id,service_id,connection_id,peer_hash,received_at),
 CONSTRAINT chk_messenger_receipt_kind CHECK(kind IN ('echo','delivery','read')),
 CONSTRAINT fk_messenger_receipt_connection FOREIGN KEY(tenant_id,service_id,connection_id) REFERENCES nexa_messaging_connection(tenant_id,service_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_messenger_media (
 id CHAR(36) PRIMARY KEY,tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,
 event_id CHAR(36) NOT NULL,conversation_id VARCHAR(17) NOT NULL,slot INT NOT NULL,media_type VARCHAR(16) NOT NULL,
 source_encrypted TEXT NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'pending',attachment_id VARCHAR(17) DEFAULT NULL,
 attempts INT NOT NULL DEFAULT 0,retry_at DATETIME DEFAULT NULL,last_error VARCHAR(40) DEFAULT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_messenger_media_slot(tenant_id,service_id,event_id,slot),
 KEY idx_messenger_media_queue(tenant_id,service_id,status,retry_at),
 CONSTRAINT chk_messenger_media_kind CHECK(media_type IN ('image','audio','video','file')),
 CONSTRAINT chk_messenger_media_status CHECK(status IN ('pending','downloading','ready','failed')),
 CONSTRAINT fk_messenger_media_event FOREIGN KEY(event_id) REFERENCES nexa_messenger_event(id),
 CONSTRAINT fk_messenger_media_conversation FOREIGN KEY(tenant_id,service_id,conversation_id) REFERENCES nexa_messaging_conversation(tenant_id,service_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
