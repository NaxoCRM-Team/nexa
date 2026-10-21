ALTER TABLE nexa_messaging_conversation
 ADD COLUMN IF NOT EXISTS contact_id VARCHAR(17) DEFAULT NULL,
 ADD INDEX IF NOT EXISTS idx_messaging_contact_scope(tenant_id,service_id,contact_id,id);
