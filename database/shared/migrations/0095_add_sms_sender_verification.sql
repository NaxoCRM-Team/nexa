ALTER TABLE nexa_messaging_connection
 ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS provider_resource_sid CHAR(34) NULL,
 DROP CONSTRAINT IF EXISTS chk_messaging_connection_state;
ALTER TABLE nexa_messaging_connection
 ADD CONSTRAINT chk_messaging_connection_state CHECK(status IN ('unverified','disabled','verified')),
 ADD CONSTRAINT chk_messaging_verified_proof CHECK(status<>'verified' OR (channel='sms' AND verified_at IS NOT NULL AND provider_resource_sid IS NOT NULL)),
 ADD COLUMN IF NOT EXISTS verified_sender_key VARCHAR(128) GENERATED ALWAYS AS (CASE WHEN status='verified' THEN CONCAT(provider,':',channel,':',sender) ELSE NULL END) PERSISTENT,
 ADD UNIQUE INDEX IF NOT EXISTS uq_messaging_verified_sender(verified_sender_key);
