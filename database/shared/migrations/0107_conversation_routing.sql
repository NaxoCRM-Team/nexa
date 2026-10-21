CREATE TABLE IF NOT EXISTS nexa_conversation_routing_rule (
 tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL,
 channel VARCHAR(16) NOT NULL, team_id VARCHAR(17) DEFAULT NULL,
 is_enabled TINYINT(1) NOT NULL DEFAULT 0, capacity INT NOT NULL DEFAULT 5,
 version_number INT NOT NULL DEFAULT 1, modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (tenant_id,service_id,channel),
 CONSTRAINT chk_conversation_routing_channel CHECK(channel IN ('chat','sms','whatsapp','messenger')),
 CONSTRAINT chk_conversation_routing_capacity CHECK(capacity BETWEEN 1 AND 50),
 CONSTRAINT fk_conversation_routing_service FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_conversation_presence (
 tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL, user_id VARCHAR(17) NOT NULL,
 is_available TINYINT(1) NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 version_number INT NOT NULL DEFAULT 1, last_assigned_at DATETIME DEFAULT NULL,
 PRIMARY KEY(tenant_id,service_id,user_id),
 KEY idx_conversation_presence(tenant_id,service_id,is_available,expires_at),
 CONSTRAINT fk_conversation_presence_service FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nexa_chat_conversation
 ADD COLUMN IF NOT EXISTS routing_state VARCHAR(16) NOT NULL DEFAULT 'unrouted',
 ADD COLUMN IF NOT EXISTS routing_reason VARCHAR(32) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS routing_checked_at DATETIME DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS routing_assigned_at DATETIME DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS routing_team_id VARCHAR(17) DEFAULT NULL,
 ADD INDEX IF NOT EXISTS idx_chat_routing(tenant_id,service_id,deleted,status,routing_checked_at);
ALTER TABLE nexa_messaging_conversation
 ADD COLUMN IF NOT EXISTS routing_state VARCHAR(16) NOT NULL DEFAULT 'unrouted',
 ADD COLUMN IF NOT EXISTS routing_reason VARCHAR(32) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS routing_checked_at DATETIME DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS routing_assigned_at DATETIME DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS routing_team_id VARCHAR(17) DEFAULT NULL,
 ADD INDEX IF NOT EXISTS idx_messaging_routing(tenant_id,service_id,deleted,status,routing_checked_at);
