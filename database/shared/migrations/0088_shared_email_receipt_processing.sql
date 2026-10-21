-- A short-lived native-import marker waits for group-mailbox association.
ALTER TABLE email ADD COLUMN IF NOT EXISTS conversation_import_pending_at DATETIME DEFAULT NULL;
