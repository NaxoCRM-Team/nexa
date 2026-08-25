# Phase 3 Customization Exit Gate

## Scope

This evidence covers the M05 tenant customization slice of Phase 3:

- tenant-created properties for Contact, Account and custom objects;
- tenant-specific create, edit, detail, list and search layout definitions;
- custom-object definitions and generic custom-object records;
- typed custom relationship definitions, searchable record linking and unlinking;
- Contact and Account form and detail integration;
- tenant-administrator ownership of definition changes;
- native record ACL checks, audit events and service entitlement scope.

This is not a declaration that every M06 sales or M07 service deliverable is complete. Their remaining exit evidence stays attached to their own backlog issues.

## Storage Contract

Migration `0032_add_tenant_customization_engine.sql` creates seven additive tables. Every table requires both `tenant_id` and `service_id`. Tenant-created fields do not alter the shared Contact or Account table, and tenant-created objects do not create arbitrary physical tables.

Native EspoCRM field controls and record screens remain the presentation foundation. Nexa stores tenant definitions, layouts, values and links in the shared-schema customization tables and resolves them only through trusted `TenantContext`.

## Automated Evidence

`TenantCustomizationContractTest.php` proves that:

- all seven tables require tenant and service ownership;
- customization routes require authentication;
- definition mutations require tenant-admin access;
- native record reads and writes preserve EspoCRM ACL checks;
- Contact and Account edit/detail screens mount the tenant property layer;
- the visual administration workspace covers fields, layouts, objects and relationships;
- custom records expose an accessible relationship manager with live record search;
- no synthetic tenant identity is hardcoded in runtime code.

`TenantCustomizationRuntimeTest.php` runs against MariaDB with two synthetic tenants and proves that:

- both tenants can independently use the same field and object keys;
- each tenant receives only its own field definitions and values;
- a tenant cannot read another tenant's custom values;
- custom-object records retain tenant and service ownership;
- failed required-property writes leave no partial custom-object records;
- relationship links enforce their configured cardinality;
- association candidates are tenant and ACL scoped, and never include the other synthetic tenant;
- relationship removal is recoverable at the record level and produces an audit event;
- a typed Contact-to-custom-object relationship is visible in Customer 360 only within its owning tenant.

Both suites are part of `scripts/dev/verify.ps1`. The live runtime suite runs during non-CI verification where a configured database is available.

## Result

The M05 tenant customization exit gate passes when the repository verification command, migration replay and live two-tenant runtime suite all pass. The broader Phase 3 gate remains open until the M06 and M07 release evidence is complete.
