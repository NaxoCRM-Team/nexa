ALTER TABLE nexa_chat_conversation
 ADD INDEX IF NOT EXISTS idx_chat_customer_timeline(tenant_id,service_id,visitor_identity_id,deleted,id);
