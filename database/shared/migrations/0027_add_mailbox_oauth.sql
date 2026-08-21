-- OAuth "connect inbox" attempts. Doubles as short-lived pending-result
-- storage: /start creates the row (tenant_id/user_id/provider known from a
-- live, authenticated request); the unauthenticated provider callback (runs
-- under Espo's platform tenant bypass, where ORM entity creation cannot
-- resolve a tenant - confirmed empirically, ORM createEntity() silently
-- drops tenantId/serviceId outside a live context and the NOT NULL columns
-- reject the insert) fills in the exchanged token fields via raw SQL only;
-- a follow-up authenticated /finish call (live tenant context restored)
-- reads the pending result and creates the real EmailAccount + permanent
-- token row via the ORM, then marks this row consumed.
CREATE TABLE IF NOT EXISTS nexa_mailbox_oauth_attempt (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    user_id VARCHAR(24) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    state_hash CHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    email_address VARCHAR(190) NULL,
    access_token_encrypted TEXT NULL,
    refresh_token_encrypted TEXT NULL,
    token_expires_at DATETIME(6) NULL,
    scopes VARCHAR(255) NULL,
    failure_reason VARCHAR(190) NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_mailbox_oauth_attempt_state (state_hash),
    KEY idx_nexa_mailbox_oauth_attempt_expiry (expires_at, consumed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per OAuth-connected EmailAccount, holding the encrypted
-- refresh/access tokens that OAuthSmtpHandler needs at send time.
-- No FK to email_account: core Espo entity tables aren't referenced
-- by hard FK elsewhere in this schema (see nexa_external_identity.user_id).
CREATE TABLE IF NOT EXISTS nexa_mailbox_oauth_token (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    email_account_id VARCHAR(17) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    email_address VARCHAR(190) NOT NULL,
    access_token_encrypted TEXT NOT NULL,
    refresh_token_encrypted TEXT NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    scopes VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_mailbox_oauth_token_account (email_account_id),
    KEY idx_nexa_mailbox_oauth_token_tenant (tenant_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
