-- Sprint 06 security governance: managed secrets, tamper-resistant audit, and controlled impersonation.

ALTER TABLE nexa_audit_event
    ADD COLUMN IF NOT EXISTS effective_user_id VARCHAR(24) NULL AFTER actor_user_id,
    ADD COLUMN IF NOT EXISTS result VARCHAR(24) NOT NULL DEFAULT 'success' AFTER action,
    ADD COLUMN IF NOT EXISTS ip_address VARCHAR(64) NULL AFTER source,
    ADD COLUMN IF NOT EXISTS user_agent VARCHAR(512) NULL AFTER ip_address,
    ADD COLUMN IF NOT EXISTS previous_hash CHAR(64) NULL AFTER metadata_json,
    ADD COLUMN IF NOT EXISTS event_hash CHAR(64) NULL AFTER previous_hash;

CREATE TABLE IF NOT EXISTS nexa_managed_secret (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    purpose VARCHAR(96) NOT NULL,
    secret_name VARCHAR(128) NOT NULL,
    key_id VARCHAR(64) NOT NULL,
    cipher_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    ciphertext LONGTEXT NOT NULL,
    created_by_id VARCHAR(24) NULL,
    rotated_by_id VARCHAR(24) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    rotated_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_managed_secret_scope (tenant_id, service_id, purpose, secret_name),
    KEY idx_nexa_managed_secret_key (key_id, cipher_version),
    CONSTRAINT fk_nexa_managed_secret_tenant FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_managed_secret_service FOREIGN KEY (service_id) REFERENCES nexa_service_definition (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_impersonation_session (
    id CHAR(36) NOT NULL,
    operator_tenant_id CHAR(36) NOT NULL,
    operator_user_id VARCHAR(24) NOT NULL,
    target_tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    target_user_id VARCHAR(24) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'requested',
    requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    approved_by_user_id VARCHAR(24) NULL,
    approved_at DATETIME(6) NULL,
    started_at DATETIME(6) NULL,
    expires_at DATETIME(6) NULL,
    ended_at DATETIME(6) NULL,
    impersonation_auth_token_id VARCHAR(24) NULL,
    correlation_id CHAR(36) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_nexa_impersonation_operator (operator_user_id, status, requested_at),
    KEY idx_nexa_impersonation_target (target_tenant_id, service_id, status, expires_at),
    KEY idx_nexa_impersonation_token (impersonation_auth_token_id),
    CONSTRAINT fk_nexa_impersonation_operator_tenant FOREIGN KEY (operator_tenant_id) REFERENCES nexa_tenant (id),
    CONSTRAINT fk_nexa_impersonation_target_tenant FOREIGN KEY (target_tenant_id) REFERENCES nexa_tenant (id),
    CONSTRAINT fk_nexa_impersonation_service FOREIGN KEY (service_id) REFERENCES nexa_service_definition (id),
    CONSTRAINT chk_nexa_impersonation_status CHECK (status IN ('requested','approved','active','expired','ended','denied'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_impersonation_action_cursor (
    action_history_id VARCHAR(24) NOT NULL,
    session_id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    audited_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (action_history_id),
    KEY idx_nexa_impersonation_cursor_session (session_id, audited_at),
    CONSTRAINT fk_nexa_impersonation_cursor_session FOREIGN KEY (session_id) REFERENCES nexa_impersonation_session (id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_impersonation_cursor_tenant FOREIGN KEY (tenant_id) REFERENCES nexa_tenant (id),
    CONSTRAINT fk_nexa_impersonation_cursor_service FOREIGN KEY (service_id) REFERENCES nexa_service_definition (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nexa_mailbox_oauth_token
    ADD COLUMN IF NOT EXISTS secret_key_id VARCHAR(64) NULL AFTER refresh_token_encrypted,
    ADD COLUMN IF NOT EXISTS secret_cipher_version SMALLINT UNSIGNED NULL AFTER secret_key_id;

DROP TRIGGER IF EXISTS nexa_audit_event_block_update;
DROP TRIGGER IF EXISTS nexa_audit_event_block_delete;

DELIMITER $$
CREATE TRIGGER nexa_audit_event_block_update
BEFORE UPDATE ON nexa_audit_event
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit events are append-only';
END$$

CREATE TRIGGER nexa_audit_event_block_delete
BEFORE DELETE ON nexa_audit_event
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit events are append-only';
END$$
DELIMITER ;
