-- NULL retains the identity of pre-parameter requests, which permitted no values.
ALTER TABLE `nexa_marketing_send_request`
    ADD COLUMN IF NOT EXISTS `parameter_fingerprint` CHAR(64) NULL;
