CREATE TABLE IF NOT EXISTS nexa_messaging_conversation (
 id VARCHAR(17) NOT NULL PRIMARY KEY, tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL,
 name VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Open', assigned_user_id VARCHAR(17) DEFAULT NULL,
 connection_id CHAR(36) NOT NULL, peer_hash CHAR(64) NOT NULL, peer_encrypted TEXT NOT NULL,
 version_number BIGINT DEFAULT NULL, created_at DATETIME DEFAULT NULL, modified_at DATETIME DEFAULT NULL,
 created_by_id VARCHAR(17) DEFAULT NULL, modified_by_id VARCHAR(17) DEFAULT NULL, deleted TINYINT(1) NOT NULL DEFAULT 0,
 UNIQUE KEY uq_messaging_conversation_peer(tenant_id,service_id,connection_id,peer_hash),
 UNIQUE KEY uq_messaging_conversation_identity(tenant_id,service_id,id),
 KEY idx_messaging_conversation_scope(tenant_id,service_id,deleted,id),
 CONSTRAINT fk_messaging_conversation_connection FOREIGN KEY(tenant_id,service_id,connection_id)
 REFERENCES nexa_messaging_connection(tenant_id,service_id,id),
 CONSTRAINT chk_messaging_conversation_status CHECK(status IN ('Open','Pending','Closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nexa_messaging_receipt
 ADD COLUMN IF NOT EXISTS conversation_id VARCHAR(17) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS projection_attempts INT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS projection_retry_at DATETIME DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS projection_error_code VARCHAR(40) DEFAULT NULL,
 ADD INDEX IF NOT EXISTS idx_messaging_receipt_conversation(tenant_id,service_id,conversation_id,received_at,id),
 ADD CONSTRAINT fk_messaging_receipt_conversation FOREIGN KEY(tenant_id,service_id,conversation_id)
 REFERENCES nexa_messaging_conversation(tenant_id,service_id,id);
