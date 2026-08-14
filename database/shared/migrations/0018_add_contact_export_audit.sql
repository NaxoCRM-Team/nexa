-- Keep tenant-scoped audit metadata on the native export attachment.
ALTER TABLE attachment
    ADD COLUMN IF NOT EXISTS nexa_export_source VARCHAR(80) NULL AFTER role,
    ADD COLUMN IF NOT EXISTS nexa_export_record_count INT UNSIGNED NULL AFTER nexa_export_source,
    ADD COLUMN IF NOT EXISTS nexa_export_format VARCHAR(24) NULL AFTER nexa_export_record_count,
    ADD COLUMN IF NOT EXISTS nexa_export_expires_at DATETIME NULL AFTER nexa_export_format,
    ADD INDEX IF NOT EXISTS idx_attachment_tenant_export_audit
        (tenant_id, service_id, role, nexa_export_expires_at);
