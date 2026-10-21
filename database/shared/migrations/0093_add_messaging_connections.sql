CREATE TABLE IF NOT EXISTS nexa_messaging_connection (
 id CHAR(36) NOT NULL PRIMARY KEY, tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL,
 name VARCHAR(120) NOT NULL, provider VARCHAR(20) NOT NULL DEFAULT 'twilio',
 channel VARCHAR(16) NOT NULL, account_sid CHAR(34) NOT NULL, sender VARCHAR(40) NOT NULL,
 secret_encrypted TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'unverified',
 version_number BIGINT NOT NULL DEFAULT 1, created_by_id VARCHAR(17) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_messaging_sender(provider,account_sid,channel,sender),
 KEY idx_messaging_connection_scope(tenant_id,service_id,id),
 CONSTRAINT fk_messaging_connection_scope FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id) ON DELETE CASCADE,
 CONSTRAINT chk_messaging_connection_channel CHECK(channel IN ('sms','whatsapp')),
 CONSTRAINT chk_messaging_connection_state CHECK(status IN ('unverified','disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_messaging_outbox (
 id CHAR(36) NOT NULL PRIMARY KEY, tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL,
 connection_id CHAR(36) NOT NULL, contact_id VARCHAR(17) NOT NULL, request_key CHAR(36) NOT NULL,
 intent_hash CHAR(64) NOT NULL, body TEXT NOT NULL, recipient VARCHAR(40) NOT NULL,
 connection_version BIGINT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'held',
 hold_reason VARCHAR(80) NOT NULL DEFAULT 'provider_acceptance_pending', created_by_id VARCHAR(17) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_messaging_request(tenant_id,service_id,request_key),
 KEY idx_messaging_outbox_scope(tenant_id,service_id,connection_id,status,id),
 CONSTRAINT fk_messaging_outbox_scope FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id) ON DELETE CASCADE,
 CONSTRAINT chk_messaging_outbox_held CHECK(status='held')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
