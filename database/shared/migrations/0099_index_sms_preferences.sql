ALTER TABLE nexa_messaging_receipt
 ADD COLUMN IF NOT EXISTS preference_processed TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS peer_hash CHAR(64) DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS opt_out_type VARCHAR(5) DEFAULT NULL,
 ADD INDEX IF NOT EXISTS idx_sms_preference(tenant_id,service_id,connection_id,peer_hash,opt_out_type),
 ADD INDEX IF NOT EXISTS idx_sms_preference_pending(tenant_id,service_id,preference_processed,kind,id);
