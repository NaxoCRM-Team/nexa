-- Tenant administrators can simplify record experiences without altering the shared schema.
-- A disabled property retains all stored values and can be enabled again at any time.
CREATE TABLE IF NOT EXISTS nexa_property_preference (
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    field_key VARCHAR(64) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_by_id VARCHAR(24) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id, service_id, entity_type, field_key),
    KEY idx_nexa_property_preference_state (tenant_id, service_id, entity_type, is_enabled),
    CONSTRAINT fk_nexa_property_preference_tenant_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES nexa_tenant_service (tenant_id, service_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_nexa_property_preference_enabled CHECK (is_enabled IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
