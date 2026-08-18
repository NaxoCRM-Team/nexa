-- Lets a tenant admin raise or lower the per-call time limit (the ceiling
-- Twilio is told to auto-disconnect a single call at) away from the system
-- default of 60 minutes - e.g. a sales team whose calls routinely run longer.
-- NULL means "use the system default" (see CallMinutesLedger::perCallCapMinutes).
ALTER TABLE nexa_tenant_service
    ADD COLUMN per_call_cap_minutes_override INT UNSIGNED NULL AFTER soft_limit_override;
