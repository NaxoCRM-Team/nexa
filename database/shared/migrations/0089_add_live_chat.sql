-- M14 website chat. Native ORM owns conversation ACL/assignment; messages stay append-only.
CREATE TABLE IF NOT EXISTS nexa_chat_widget (
 id CHAR(36) NOT NULL PRIMARY KEY, tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL,
 public_key CHAR(48) NOT NULL, name VARCHAR(120) NOT NULL, allowed_origins_json LONGTEXT NOT NULL,
 title VARCHAR(120) NOT NULL, welcome VARCHAR(500) NOT NULL, color CHAR(7) NOT NULL DEFAULT '#147d70',
 position VARCHAR(5) NOT NULL DEFAULT 'right', draggable TINYINT(1) NOT NULL DEFAULT 1,
 privacy_url VARCHAR(1000) NOT NULL DEFAULT '', is_enabled TINYINT(1) NOT NULL DEFAULT 0,
 version_number BIGINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_chat_widget_key(public_key), KEY idx_chat_widget_scope(tenant_id,service_id),
 CONSTRAINT fk_chat_widget_scope FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_chat_conversation (
 id VARCHAR(17) NOT NULL PRIMARY KEY, tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL,
 name VARCHAR(255) DEFAULT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Open', assigned_user_id VARCHAR(17) DEFAULT NULL,
 widget_id CHAR(36) NOT NULL, origin VARCHAR(500) NOT NULL, token_hash CHAR(64) NOT NULL,
 visitor_key_hash CHAR(64) NOT NULL, start_nonce CHAR(36) NOT NULL, expires_at DATETIME NOT NULL,
 visitor_identity_id CHAR(36) DEFAULT NULL, identity_issuer_id VARCHAR(24) DEFAULT NULL,
 version_number BIGINT DEFAULT NULL, created_at DATETIME DEFAULT NULL, modified_at DATETIME DEFAULT NULL,
 created_by_id VARCHAR(17) DEFAULT NULL, modified_by_id VARCHAR(17) DEFAULT NULL, deleted TINYINT(1) NOT NULL DEFAULT 0,
 UNIQUE KEY uq_chat_start(tenant_id,service_id,widget_id,start_nonce),
 KEY idx_chat_scope(tenant_id,service_id,deleted,id), KEY idx_chat_admission(tenant_id,service_id,created_at),
 CONSTRAINT fk_chat_conversation_scope FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_chat_message (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, tenant_id CHAR(36) NOT NULL, service_id CHAR(36) NOT NULL,
 conversation_id VARCHAR(17) NOT NULL, nonce CHAR(36) NOT NULL, author_kind VARCHAR(7) NOT NULL,
 author_user_id VARCHAR(17) DEFAULT NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_chat_message(tenant_id,service_id,conversation_id,nonce),
 KEY idx_chat_history(tenant_id,service_id,conversation_id,id), KEY idx_chat_volume(tenant_id,service_id,created_at),
 CONSTRAINT fk_chat_message_scope FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id) ON DELETE CASCADE,
 CONSTRAINT chk_chat_author CHECK(author_kind IN ('visitor','staff'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
