-- Published widget definitions are copied into each new conversation.
ALTER TABLE nexa_chat_widget
 ADD COLUMN IF NOT EXISTS bot_definition_json LONGTEXT NULL,
 ADD COLUMN IF NOT EXISTS bot_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE nexa_chat_conversation
 ADD COLUMN IF NOT EXISTS bot_snapshot LONGTEXT NULL,
 ADD COLUMN IF NOT EXISTS bot_state VARCHAR(20) NOT NULL DEFAULT 'none',
 ADD COLUMN IF NOT EXISTS bot_step VARCHAR(40) NULL,
 ADD COLUMN IF NOT EXISTS bot_receipts LONGTEXT NULL;
ALTER TABLE nexa_chat_message DROP CONSTRAINT IF EXISTS chk_chat_author;
ALTER TABLE nexa_chat_message ADD CONSTRAINT chk_chat_author CHECK(author_kind IN ('visitor','staff','bot'));
