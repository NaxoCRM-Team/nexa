-- Register the capability without silently assigning a tier or granting sends.
INSERT IGNORE INTO nexa_service_definition (id,service_key,display_name,description,measurement_unit,status)
VALUES ('10000000-0000-4000-8000-000000000075','email.transactional','Transactional email','Monthly operational email message allowance','messages','active');
