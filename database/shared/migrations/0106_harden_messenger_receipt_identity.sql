ALTER TABLE nexa_messenger_event
 ADD UNIQUE INDEX IF NOT EXISTS uq_messenger_event_scope(tenant_id,service_id,id),
 ADD INDEX IF NOT EXISTS idx_messenger_preference(tenant_id,service_id,conversation_id,is_opt_in,is_opt_out,provider_at);
ALTER TABLE nexa_messenger_media
 DROP FOREIGN KEY IF EXISTS fk_messenger_media_event,
 ADD CONSTRAINT fk_messenger_media_scoped_event FOREIGN KEY(tenant_id,service_id,event_id) REFERENCES nexa_messenger_event(tenant_id,service_id,id);
ALTER TABLE nexa_messenger_receipt ADD COLUMN IF NOT EXISTS payload_hash CHAR(64) DEFAULT NULL;
