-- Native Webhook and WebhookQueueItem remain the configuration and payload records.
-- Workflow delivery adds durable identity, leases and uncertain-outcome evidence.
CREATE TABLE IF NOT EXISTS nexa_workflow_webhook_effect (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    workflow_id CHAR(36) NOT NULL,
    run_id CHAR(36) NOT NULL,
    node_id VARCHAR(40) NOT NULL,
    queue_item_id VARCHAR(24) NOT NULL,
    webhook_id VARCHAR(24) NOT NULL,
    execution_user_id VARCHAR(24) NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL,
    retry_seconds INT UNSIGNED NOT NULL,
    due_at DATETIME(6) NOT NULL,
    lease_token CHAR(64) NULL,
    lease_expires_at DATETIME(6) NULL,
    last_http_status INT NULL,
    last_error_code VARCHAR(64) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_workflow_webhook_step (tenant_id,service_id,run_id,node_id),
    UNIQUE KEY uq_workflow_webhook_queue (queue_item_id),
    KEY idx_workflow_webhook_due (tenant_id,service_id,status,due_at),
    CONSTRAINT fk_workflow_webhook_run FOREIGN KEY (run_id) REFERENCES nexa_workflow_run (id) ON DELETE CASCADE,
    CONSTRAINT chk_workflow_webhook_status CHECK (status IN ('pending','sending','success','failed','uncertain','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_workflow_webhook_attempt (
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    effect_id CHAR(36) NOT NULL,
    attempt_number INT UNSIGNED NOT NULL,
    outcome VARCHAR(16) NOT NULL,
    http_status INT NULL,
    error_code VARCHAR(64) NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    PRIMARY KEY (tenant_id,service_id,effect_id,attempt_number),
    CONSTRAINT fk_workflow_webhook_attempt FOREIGN KEY (effect_id) REFERENCES nexa_workflow_webhook_effect (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
