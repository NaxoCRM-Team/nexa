-- Give every interactive user one normalized, globally unique login identity.
-- NULL remains available for system/API users and legacy administrators until
-- an email is assigned; MariaDB permits multiple NULL values in a unique key.
ALTER TABLE `user`
    ADD COLUMN IF NOT EXISTS `login_email` VARCHAR(190) NULL AFTER `user_name`;

UPDATE `user` u
INNER JOIN entity_email_address eea
    ON eea.entity_id = u.id
    AND eea.entity_type = 'User'
    AND eea.`primary` = 1
    AND eea.deleted = 0
    AND eea.tenant_id = u.tenant_id
    AND eea.service_id = u.service_id
INNER JOIN email_address ea
    ON ea.id = eea.email_address_id
    AND ea.deleted = 0
    AND ea.tenant_id = u.tenant_id
    AND ea.service_id = u.service_id
SET u.login_email = LOWER(TRIM(ea.name))
WHERE u.deleted = 0
    AND u.type NOT IN ('api', 'system')
    AND NULLIF(TRIM(ea.name), '') IS NOT NULL
    AND u.login_email IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS `UNIQ_USER_LOGIN_EMAIL`
    ON `user` (`login_email`);
