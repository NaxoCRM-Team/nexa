-- Keep the immutable communication-preference audit aligned with every
-- outbound channel supported by the Contact preference service and UI.
ALTER TABLE nexa_communication_preference
    DROP CONSTRAINT chk_nexa_preference_channel,
    ADD CONSTRAINT chk_nexa_preference_channel
        CHECK (channel IN (
            'email', 'phone', 'sms', 'whatsapp', 'linkedin', 'postal', 'live_chat'
        ));
