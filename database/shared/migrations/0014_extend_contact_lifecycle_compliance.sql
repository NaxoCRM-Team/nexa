-- Add the customer lifecycle and lawful-processing classifications captured by the Contact form.
-- Tenant and service ownership remains enforced centrally by the ORM and shared-schema constraints.

ALTER TABLE contact
    ADD COLUMN IF NOT EXISTS lifecycle_stage VARCHAR(80) NULL AFTER source,
    ADD COLUMN IF NOT EXISTS legal_basis VARCHAR(120) NULL AFTER marketing_status,
    ADD INDEX IF NOT EXISTS idx_contact_tenant_lifecycle (
        tenant_id, service_id, lifecycle_stage, deleted
    );
