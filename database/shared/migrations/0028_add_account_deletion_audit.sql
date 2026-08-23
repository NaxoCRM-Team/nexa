-- Account recovery uses the same two-month soft-delete policy as Contacts.
ALTER TABLE account
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER deleted,
    ADD COLUMN IF NOT EXISTS deleted_by_id VARCHAR(17) NULL AFTER deleted_at,
    ADD INDEX IF NOT EXISTS idx_account_tenant_deleted_at (tenant_id, deleted, deleted_at),
    ADD INDEX IF NOT EXISTS idx_account_deleted_by_id (deleted_by_id);
