-- An unverified configuration is not proof of global sender ownership.
ALTER TABLE nexa_messaging_connection
 DROP INDEX IF EXISTS uq_messaging_sender,
 ADD UNIQUE INDEX IF NOT EXISTS uq_messaging_sender_scope(tenant_id,service_id,provider,account_sid,channel,sender);
