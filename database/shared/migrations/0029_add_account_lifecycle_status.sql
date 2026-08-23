-- Track the company-level customer journey and active sales follow-up state.
-- Values remain tenant and service scoped through the central Account ORM boundary.

ALTER TABLE account
    ADD COLUMN IF NOT EXISTS lifecycle_stage VARCHAR(80) NULL AFTER lead_score,
    ADD COLUMN IF NOT EXISTS lead_status VARCHAR(80) NULL AFTER lifecycle_stage,
    ADD INDEX IF NOT EXISTS idx_account_tenant_lifecycle (
        tenant_id, service_id, lifecycle_stage, deleted
    ),
    ADD INDEX IF NOT EXISTS idx_account_tenant_lead_status (
        tenant_id, service_id, lead_status, deleted
    );
