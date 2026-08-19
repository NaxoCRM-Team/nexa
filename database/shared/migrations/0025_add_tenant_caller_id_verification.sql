ALTER TABLE nexa_tenant_service
    ADD COLUMN caller_number VARCHAR(20) DEFAULT NULL AFTER per_call_cap_minutes_override,
    ADD COLUMN caller_number_status VARCHAR(16) NOT NULL DEFAULT 'unverified' AFTER caller_number,
    ADD COLUMN caller_number_verification_started_at DATETIME(6) DEFAULT NULL AFTER caller_number_status,
    ADD CONSTRAINT ck_nexa_tenant_service_caller_number_status
        CHECK (caller_number_status IN ('unverified', 'pending', 'verified'));
