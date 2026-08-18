-- Tracks whether the tenant's admins have already been notified that voice
-- calling usage crossed the plan's soft_limit warning threshold for a given
-- period. Period-keyed so the warning naturally re-arms next month. A row
-- existing means "already notified" - the claim is a single atomic
-- INSERT ... ON DUPLICATE KEY UPDATE (see CallMinutesLedger::claimSoftLimitNotice),
-- so concurrent calls crossing the threshold at once can never double-notify.
CREATE TABLE IF NOT EXISTS nexa_soft_limit_notice (
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    period_key VARCHAR(32) NOT NULL,
    notified_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id, service_id, period_key),
    CONSTRAINT fk_nexa_soft_limit_notice_tenant_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES nexa_tenant_service (tenant_id, service_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
