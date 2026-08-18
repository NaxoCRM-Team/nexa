-- Monthly calling-minute allowances per plan tier for voice.calling_minutes.
-- soft_limit is an 80%-of-allowance warning threshold; hard_limit blocks further
-- calls until the period resets (see nexa_plan_service.configuration_json below,
-- mirroring the existing monthly-reset pattern already used by marketing.email).
INSERT INTO nexa_plan_service
    (plan_id, service_id, is_enabled, soft_limit, hard_limit, configuration_json)
VALUES
    ('10000000-0000-4000-8000-000000000001', '20000000-0000-4000-8000-000000000006', 1, 80, 100, JSON_OBJECT('period', 'month')),
    ('10000000-0000-4000-8000-000000000002', '20000000-0000-4000-8000-000000000006', 1, 400, 500, JSON_OBJECT('period', 'month')),
    ('10000000-0000-4000-8000-000000000003', '20000000-0000-4000-8000-000000000006', 1, 1600, 2000, JSON_OBJECT('period', 'month'))
ON DUPLICATE KEY UPDATE
    is_enabled = VALUES(is_enabled),
    soft_limit = VALUES(soft_limit),
    hard_limit = VALUES(hard_limit),
    configuration_json = VALUES(configuration_json);
