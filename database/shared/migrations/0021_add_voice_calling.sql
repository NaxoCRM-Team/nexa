-- Add the voice.calling_minutes billable service and the call-session audit/billing
-- anchor table backing Twilio-powered click-to-call from the Contact detail page.
INSERT INTO nexa_service_definition (id, service_key, display_name, description, measurement_unit, status) VALUES
    ('20000000-0000-4000-8000-000000000006', 'voice.calling_minutes', 'Voice Calling Minutes', 'Outbound browser-to-phone calling minutes', 'minutes', 'active')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), description = VALUES(description), measurement_unit = VALUES(measurement_unit), status = VALUES(status);

-- One row per call attempt. Twilio's webhooks carry no EspoCRM session, so this
-- table (looked up by correlation_id, generated at initiate time) is how the
-- webhook handlers resolve which tenant a given call belongs to.
CREATE TABLE IF NOT EXISTS nexa_call_session (
    id CHAR(36) NOT NULL,
    tenant_id CHAR(36) NOT NULL,
    service_id CHAR(36) NOT NULL,
    contact_id VARCHAR(17) NOT NULL,
    initiated_by_user_id VARCHAR(17) NOT NULL,
    correlation_id CHAR(36) NOT NULL,
    twilio_call_sid VARCHAR(64) NULL,
    from_number VARCHAR(32) NOT NULL,
    to_number VARCHAR(32) NOT NULL,
    direction VARCHAR(16) NOT NULL DEFAULT 'outbound',
    status VARCHAR(24) NOT NULL DEFAULT 'initiated',
    duration_seconds INT UNSIGNED NULL,
    billed_minutes INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ended_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nexa_call_session_correlation (correlation_id),
    UNIQUE KEY uq_nexa_call_session_twilio_sid (twilio_call_sid),
    INDEX idx_nexa_call_session_tenant_contact
        (tenant_id, service_id, contact_id, created_at),
    INDEX idx_nexa_call_session_status
        (tenant_id, service_id, status),
    CONSTRAINT fk_nexa_call_session_tenant_service
        FOREIGN KEY (tenant_id, service_id)
        REFERENCES nexa_tenant_service (tenant_id, service_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_nexa_call_session_direction
        CHECK (direction IN ('outbound', 'inbound')),
    CONSTRAINT chk_nexa_call_session_status
        CHECK (status IN ('initiated', 'ringing', 'in-progress', 'completed', 'no-answer', 'busy', 'failed', 'canceled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill every EXISTING tenant with a nexa_tenant_service entitlement row for
-- the new service (status only - no override limits). Migrations always run
-- before seeds (see apply-shared-schema.ps1), so this cannot join against
-- nexa_plan_service, whose voice.calling_minutes rows are seed data (see
-- database/shared/seeds/0003_voice_calling_plan_defaults.sql) - mirrors the
-- unconditional backfill shape already used by 0010_enforce_core_service_ownership.
-- Leaving the override columns NULL is intentional: minute limits are resolved
-- dynamically from the tenant's plan at usage-check time (Phase 2), the same way
-- an override-less row already behaves for every other service. Brand-new
-- tenants get a fully-populated row automatically at signup instead (see
-- SignupService, which copies nexa_plan_service for the tenant's chosen plan).
INSERT INTO nexa_tenant_service (tenant_id, service_id, status, starts_at)
SELECT id, '20000000-0000-4000-8000-000000000006', 'active', CURRENT_TIMESTAMP(6)
FROM nexa_tenant
ON DUPLICATE KEY UPDATE status = VALUES(status);
