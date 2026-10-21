-- The chat entity uses native ORM/ACL, but migrations own its capability constraints.
-- Restore constraints if an early native rebuild compared this new table to generic metadata.
ALTER TABLE nexa_chat_conversation
 MODIFY widget_id CHAR(36) NOT NULL, MODIFY origin VARCHAR(500) NOT NULL,
 MODIFY token_hash CHAR(64) NOT NULL, MODIFY visitor_key_hash CHAR(64) NOT NULL,
 MODIFY start_nonce CHAR(36) NOT NULL, MODIFY expires_at DATETIME NOT NULL,
 ADD UNIQUE INDEX IF NOT EXISTS uq_chat_start(tenant_id,service_id,widget_id,start_nonce),
 ADD INDEX IF NOT EXISTS idx_chat_scope(tenant_id,service_id,deleted,id),
 ADD INDEX IF NOT EXISTS idx_chat_admission(tenant_id,service_id,created_at);
SET @chat_fk_sql=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='nexa_chat_conversation' AND CONSTRAINT_NAME='fk_chat_conversation_scope')=0,
 'ALTER TABLE nexa_chat_conversation ADD CONSTRAINT fk_chat_conversation_scope FOREIGN KEY(tenant_id,service_id) REFERENCES nexa_tenant_service(tenant_id,service_id) ON DELETE CASCADE', 'SELECT 1');
PREPARE chat_fk FROM @chat_fk_sql;
EXECUTE chat_fk;
DEALLOCATE PREPARE chat_fk;
