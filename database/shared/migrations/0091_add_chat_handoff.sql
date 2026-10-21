-- Handoff is independent of native Open/Pending/Closed triage state.
ALTER TABLE nexa_chat_conversation
 ADD COLUMN IF NOT EXISTS handoff_requested_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS handoff_answered_at DATETIME NULL;
