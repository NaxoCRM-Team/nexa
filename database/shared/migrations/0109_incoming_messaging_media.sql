ALTER TABLE nexa_messaging_receipt
 ADD COLUMN IF NOT EXISTS media_indexed TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS media_index_attempts INT NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS media_index_retry_at DATETIME DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS media_index_error VARCHAR(40) DEFAULT NULL,
 ADD INDEX IF NOT EXISTS idx_receipt_media_index(tenant_id,service_id,kind,media_indexed,media_index_retry_at);

CREATE TABLE IF NOT EXISTS nexa_messaging_media (
 id CHAR(36) PRIMARY KEY,tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,
 receipt_id CHAR(36) NOT NULL,conversation_id VARCHAR(17) NOT NULL,slot INT NOT NULL,
 content_type VARCHAR(255) NOT NULL,source_encrypted TEXT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',attachment_id VARCHAR(17) DEFAULT NULL,
 claim_token CHAR(36) DEFAULT NULL,attempts INT NOT NULL DEFAULT 0,retry_at DATETIME DEFAULT NULL,last_error VARCHAR(40) DEFAULT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_messaging_media_slot(tenant_id,service_id,receipt_id,slot),
 KEY idx_messaging_media_queue(tenant_id,service_id,status,retry_at),
 CONSTRAINT chk_messaging_media_status CHECK(status IN ('pending','downloading','ready','failed')),
 CONSTRAINT fk_messaging_media_receipt FOREIGN KEY(receipt_id) REFERENCES nexa_messaging_receipt(id),
 CONSTRAINT fk_messaging_media_conversation FOREIGN KEY(tenant_id,service_id,conversation_id) REFERENCES nexa_messaging_conversation(tenant_id,service_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
