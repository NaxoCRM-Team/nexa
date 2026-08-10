-- Extend the canonical Contact and Account records with specification-backed profile fields.
-- Lifecycle history, consent, segments and activity metrics remain in their owned tables.

ALTER TABLE contact
    ADD COLUMN IF NOT EXISTS department VARCHAR(120) NULL AFTER middle_name,
    ADD COLUMN IF NOT EXISTS website VARCHAR(255) NULL AFTER department,
    ADD COLUMN IF NOT EXISTS source VARCHAR(255) NULL AFTER website,
    ADD COLUMN IF NOT EXISTS lead_status VARCHAR(255) NULL AFTER source,
    ADD COLUMN IF NOT EXISTS marketing_status VARCHAR(255) NULL DEFAULT 'Non-Marketing' AFTER lead_status,
    ADD COLUMN IF NOT EXISTS lead_score INT NULL DEFAULT 0 AFTER marketing_status,
    ADD INDEX IF NOT EXISTS idx_contact_tenant_marketing_status (tenant_id, service_id, marketing_status),
    ADD INDEX IF NOT EXISTS idx_contact_tenant_source (tenant_id, service_id, source);

ALTER TABLE account
    ADD COLUMN IF NOT EXISTS annual_revenue DOUBLE NULL AFTER industry,
    ADD COLUMN IF NOT EXISTS annual_revenue_currency VARCHAR(3) NULL AFTER annual_revenue,
    ADD COLUMN IF NOT EXISTS number_of_employees INT NULL AFTER annual_revenue_currency,
    ADD COLUMN IF NOT EXISTS parent_account_id VARCHAR(17) NULL AFTER number_of_employees,
    ADD COLUMN IF NOT EXISTS lead_score INT NULL DEFAULT 0 AFTER parent_account_id,
    ADD INDEX IF NOT EXISTS idx_account_tenant_parent (tenant_id, service_id, parent_account_id);
