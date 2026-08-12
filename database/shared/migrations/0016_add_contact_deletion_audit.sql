-- Preserve who deleted a Contact and when throughout its 90-day recovery window.
ALTER TABLE contact
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER deleted,
    ADD COLUMN IF NOT EXISTS deleted_by_id VARCHAR(17) NULL AFTER deleted_at,
    ADD INDEX IF NOT EXISTS idx_contact_tenant_deleted_at (tenant_id, deleted, deleted_at),
    ADD INDEX IF NOT EXISTS idx_contact_deleted_by_id (deleted_by_id);
