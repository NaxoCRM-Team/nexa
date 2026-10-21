ALTER TABLE nexa_messaging_connection
 ADD UNIQUE INDEX IF NOT EXISTS uq_messaging_connection_identity(tenant_id,service_id,id);

CREATE TABLE IF NOT EXISTS nexa_messaging_receipt (
 id CHAR(36) NOT NULL PRIMARY KEY,
 tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL, connection_id CHAR(36) NOT NULL,
 kind VARCHAR(16) NOT NULL, provider_message_id CHAR(34) NOT NULL,
 event_key CHAR(64) NOT NULL, payload_hash CHAR(64) NOT NULL,
 payload_encrypted MEDIUMTEXT NOT NULL, connection_version BIGINT NOT NULL,
 disposition VARCHAR(24) NOT NULL, received_at DATETIME NOT NULL,
 UNIQUE KEY uq_messaging_receipt_event(tenant_id,service_id,connection_id,event_key),
 KEY idx_messaging_receipt_volume(tenant_id,service_id,received_at),
 CONSTRAINT fk_messaging_receipt_connection FOREIGN KEY(tenant_id,service_id,connection_id)
 REFERENCES nexa_messaging_connection(tenant_id,service_id,id),
 CONSTRAINT chk_messaging_receipt_kind CHECK(kind IN ('inbound','status')),
 CONSTRAINT chk_messaging_receipt_disposition CHECK(disposition IN ('received','unmatched_status'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
