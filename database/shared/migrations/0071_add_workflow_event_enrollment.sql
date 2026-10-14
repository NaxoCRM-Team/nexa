-- Event payloads remain in the canonical behavior store; these tables hold processing evidence only.
CREATE TABLE IF NOT EXISTS nexa_workflow_trigger_scan (
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    workflow_id CHAR(36) NOT NULL,
    last_scanned_at DATETIME(6) NOT NULL,
    blocked_reason VARCHAR(40) NULL,
    blocked_version INT UNSIGNED NULL,
    PRIMARY KEY (tenant_id,service_id,workflow_id),
    CONSTRAINT fk_workflow_trigger_scan FOREIGN KEY (tenant_id,service_id,workflow_id)
        REFERENCES nexa_workflow (tenant_id,service_id,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_workflow_trigger_receipt (
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    workflow_id CHAR(36) NOT NULL,
    event_id CHAR(36) NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    outcome VARCHAR(16) NOT NULL,
    reason VARCHAR(40) NULL,
    run_id CHAR(36) NULL,
    processed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id,service_id,workflow_id,event_id),
    CONSTRAINT fk_workflow_trigger_receipt FOREIGN KEY (tenant_id,service_id,workflow_id,version_number)
        REFERENCES nexa_workflow_version (tenant_id,service_id,workflow_id,version_number) ON DELETE CASCADE,
    CONSTRAINT chk_workflow_trigger_outcome CHECK (outcome IN ('enrolled','skipped'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_behavior_workflow_received
    ON nexa_behavior_event (tenant_id,service_id,event_type,received_at,id);
