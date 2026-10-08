-- Historical versions remain NULL; sender approval evidence must not be fabricated.
ALTER TABLE `nexa_marketing_email_version`
    ADD COLUMN IF NOT EXISTS `delivery_manifest_json` JSON NULL;
