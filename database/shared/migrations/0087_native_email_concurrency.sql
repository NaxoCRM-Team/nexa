-- Enable the native Email optimistic-concurrency attribute, not a second revision ledger.
ALTER TABLE email ADD COLUMN IF NOT EXISTS version_number BIGINT DEFAULT NULL;
