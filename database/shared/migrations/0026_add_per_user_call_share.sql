ALTER TABLE nexa_tenant_service
    ADD COLUMN user_call_share_minutes_override INT UNSIGNED DEFAULT NULL AFTER per_call_cap_minutes_override;

CREATE TABLE nexa_user_usage_counter (
    tenant_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    period_key VARCHAR(16) NOT NULL,
    quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reserved_quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id, user_id, service_id, period_key),
    KEY ix_nexa_user_usage_counter_tenant_service (tenant_id, service_id),
    CONSTRAINT fk_nexa_user_usage_counter_service
        FOREIGN KEY (tenant_id, service_id) REFERENCES nexa_tenant_service (tenant_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nexa_user_credit_grant (
    tenant_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    period_key VARCHAR(16) NOT NULL,
    granted_minutes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant_id, user_id, service_id, period_key),
    CONSTRAINT fk_nexa_user_credit_grant_service
        FOREIGN KEY (tenant_id, service_id) REFERENCES nexa_tenant_service (tenant_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
