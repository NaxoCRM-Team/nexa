-- Native Email remains the message, ownership and transport record.
ALTER TABLE email ADD COLUMN IF NOT EXISTS conversation_status VARCHAR(255) DEFAULT 'Open';
