CREATE TABLE IF NOT EXISTS nexa_chat_privacy_policy (
 tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,
 retention_days INT NOT NULL DEFAULT 90,legal_hold TINYINT(1) NOT NULL DEFAULT 0,
 hold_reason VARCHAR(500) NOT NULL DEFAULT '',version_number INT NOT NULL DEFAULT 1,
 modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(tenant_id,service_id),
 CONSTRAINT chk_chat_privacy_days CHECK(retention_days BETWEEN 1 AND 3650),
 CONSTRAINT fk_chat_privacy_service FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nexa_chat_erasure (
 tenant_id CHAR(36) NOT NULL,service_id CHAR(36) NOT NULL,
 conversation_id VARCHAR(17) NOT NULL,widget_id CHAR(36) NOT NULL,nonce_hash CHAR(64) NOT NULL,
 requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,completed_at DATETIME DEFAULT NULL,
 retry_at DATETIME DEFAULT NULL,attempts INT NOT NULL DEFAULT 0,error_code VARCHAR(40) DEFAULT NULL,
 PRIMARY KEY(tenant_id,service_id,conversation_id),
 UNIQUE KEY uq_chat_erasure_nonce(tenant_id,service_id,widget_id,nonce_hash),
 KEY idx_chat_erasure_pending(tenant_id,service_id,completed_at,retry_at),
 CONSTRAINT fk_chat_erasure_service FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE nexa_chat_conversation
 ADD COLUMN IF NOT EXISTS privacy_deleted_at DATETIME DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS privacy_erasing_at DATETIME DEFAULT NULL,
 ADD INDEX IF NOT EXISTS idx_chat_privacy(tenant_id,service_id,deleted,privacy_deleted_at,expires_at);
UPDATE nexa_chat_conversation SET privacy_deleted_at=COALESCE(modified_at,created_at,UTC_TIMESTAMP()) WHERE deleted=1 AND privacy_deleted_at IS NULL;
