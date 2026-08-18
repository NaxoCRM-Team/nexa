-- Phase 2 of voice calling: race-free reservation-based admission control (so a
-- tenant's shared minute pool can never be over-admitted no matter how many
-- users call at once) plus a credit-request/approval workflow for when the
-- pool runs dry. See the "Click-to-Call" plan doc for the full design.

-- Minutes provisionally held by in-flight calls, tracked separately from
-- `quantity` (actually billed minutes, still written only by settlement).
ALTER TABLE nexa_usage_counter
    ADD COLUMN reserved_quantity BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity;

-- Records exactly how much a specific call reserved, so settlement (whether
-- from a real Twilio webhook or the stale-reservation cleanup job) knows
-- precisely how much to release back to the pool.
ALTER TABLE nexa_call_session
    ADD COLUMN reserved_minutes INT UNSIGNED NULL AFTER billed_minutes;

-- Admin-approved top-ups to a tenant's effective limit. Period-keyed exactly
-- like nexa_usage_counter, so an approved grant does NOT roll over to the
-- next month - matches the "no rollover" rule already established elsewhere.
CREATE TABLE IF NOT EXISTS nexa_tenant_credit_grant (
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    period_key VARCHAR(32) NOT NULL,
    granted_minutes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id, service_id, period_key),
    CONSTRAINT fk_nexa_tenant_credit_grant_tenant_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES nexa_tenant_service (tenant_id, service_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per credit request. Kept as a raw SaaS-admin table (same shape as
-- nexa_call_session), not a full EspoCRM entity - this app has never
-- registered a brand-new entity type, and the review UI is a small hand-rolled
-- admin panel rather than a native list/detail view.
CREATE TABLE IF NOT EXISTS nexa_call_credit_request (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    requested_by_user_id VARCHAR(17) NOT NULL,
    requested_minutes INT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    reviewed_by_user_id VARCHAR(17) NULL,
    reviewed_at DATETIME(6) NULL,
    granted_minutes INT UNSIGNED NULL,
    decision_note TEXT NULL,
    period_key VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    INDEX idx_nexa_call_credit_request_tenant_status
        (tenant_id, service_id, status, created_at),
    CONSTRAINT fk_nexa_call_credit_request_tenant_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES nexa_tenant_service (tenant_id, service_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_nexa_call_credit_request_status
        CHECK (status IN ('pending', 'approved', 'denied'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
