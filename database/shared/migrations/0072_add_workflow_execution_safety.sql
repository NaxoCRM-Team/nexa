-- Durable round-robin position and rollback-safe native retry state.
CREATE TABLE IF NOT EXISTS nexa_workflow_execution_scan (
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    workflow_id CHAR(36) NOT NULL,
    last_processed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (tenant_id,service_id,workflow_id),
    CONSTRAINT fk_workflow_execution_scan FOREIGN KEY (tenant_id,service_id,workflow_id)
        REFERENCES nexa_workflow (tenant_id,service_id,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_workflow_step_attempt (
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    run_id CHAR(36) NOT NULL,
    node_id VARCHAR(40) NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code VARCHAR(64) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (tenant_id,service_id,run_id,node_id),
    CONSTRAINT fk_workflow_step_attempt FOREIGN KEY (run_id)
        REFERENCES nexa_workflow_run (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only missing grants explicitly enabled by an active plan are provisioned.
-- Never overwrite a disabled grant or an operator's tenant limits.
INSERT IGNORE INTO nexa_tenant_service (tenant_id,service_id,status)
SELECT s.tenant_id,p.service_id,'active'
FROM nexa_tenant_subscription s
INNER JOIN nexa_plan_definition pd ON pd.id=s.plan_id AND pd.status='active'
INNER JOIN nexa_plan_service p ON p.plan_id=s.plan_id AND p.is_enabled=1
INNER JOIN nexa_service_definition d ON d.id=p.service_id AND d.service_key='automation' AND d.status='active'
INNER JOIN nexa_tenant t ON t.id=s.tenant_id AND t.status='active'
WHERE s.status='active' OR (s.status='trialing' AND s.trial_ends_at>UTC_TIMESTAMP(6));
