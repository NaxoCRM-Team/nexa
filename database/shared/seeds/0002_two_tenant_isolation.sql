-- Synthetic tenants used only for repeatable local and CI isolation tests.
INSERT INTO nexa_tenant (id, slug, display_name, status, region, timezone) VALUES
    ('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'Isolation Alpha', 'active', 'local', 'UTC'),
    ('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'Isolation Beta', 'active', 'local', 'UTC')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), status = VALUES(status);

INSERT INTO nexa_tenant_domain
    (id, tenant_id, hostname, domain_type, verification_status, is_primary, verified_at)
VALUES
    ('30000000-0000-4000-8100-000000000001', '30000000-0000-4000-8000-000000000001', 'tenant-a.localhost', 'local', 'verified', 1, CURRENT_TIMESTAMP(6)),
    ('30000000-0000-4000-8100-000000000002', '30000000-0000-4000-8000-000000000002', 'tenant-b.localhost', 'local', 'verified', 1, CURRENT_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), verification_status = VALUES(verification_status);

INSERT INTO nexa_tenant_subscription (id, tenant_id, plan_id, status) VALUES
    ('30000000-0000-4000-8200-000000000001', '30000000-0000-4000-8000-000000000001', '10000000-0000-4000-8000-000000000001', 'active'),
    ('30000000-0000-4000-8200-000000000002', '30000000-0000-4000-8000-000000000002', '10000000-0000-4000-8000-000000000001', 'active')
ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), status = VALUES(status);

INSERT INTO nexa_tenant_service (tenant_id, service_id, status) VALUES
    ('30000000-0000-4000-8000-000000000001', '20000000-0000-4000-8000-000000000001', 'active'),
    ('30000000-0000-4000-8000-000000000002', '20000000-0000-4000-8000-000000000001', 'active'),
    ('30000000-0000-4000-8000-000000000001', '20000000-0000-4000-8000-000000000004', 'active'),
    ('30000000-0000-4000-8000-000000000002', '20000000-0000-4000-8000-000000000004', 'active')
ON DUPLICATE KEY UPDATE status = VALUES(status);
