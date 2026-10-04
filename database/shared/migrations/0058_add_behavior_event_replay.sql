-- Phase 5: auditable and idempotent behavioral-event replay requests.

CREATE TABLE IF NOT EXISTS nexa_behavior_event_replay (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    behavior_event_id CHAR(36) NOT NULL,
    replay_key VARCHAR(191) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'queued',
    requested_by_id VARCHAR(24) NULL,
    correlation_id CHAR(36) NOT NULL,
    requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    processed_at DATETIME(6) NULL,
    failure_message VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_behavior_replay_key (tenant_id, service_id, behavior_event_id, replay_key),
    KEY idx_nexa_behavior_replay_status (tenant_id, service_id, status, requested_at),
    CONSTRAINT fk_nexa_behavior_replay_tenant_service
        FOREIGN KEY (tenant_id, service_id) REFERENCES nexa_tenant_service (tenant_id, service_id) ON DELETE CASCADE,
    CONSTRAINT fk_nexa_behavior_replay_event
        FOREIGN KEY (behavior_event_id) REFERENCES nexa_behavior_event (id) ON DELETE CASCADE,
    CONSTRAINT chk_nexa_behavior_replay_status
        CHECK (status IN ('queued', 'processing', 'completed', 'failed', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

