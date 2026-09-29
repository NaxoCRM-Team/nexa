# Security Governance Operations

## Managed secrets

Nexa stores tenant integration credentials as authenticated AES-256-GCM envelopes. Each envelope contains only a cipher version, key ID, nonce, authentication tag and ciphertext. Key material stays in the deployment environment and must never be committed, stored in SQL, or printed in logs.

Configure a key ring and select one active write key:

```dotenv
NEXA_SECRET_ACTIVE_KEY_ID=production-2026-01
NEXA_SECRET_KEYS={"production-2025-01":"BASE64_32_BYTE_OLD_KEY","production-2026-01":"BASE64_32_BYTE_NEW_KEY"}
```

Keep the legacy `NEXA_AUTH_SECRET_KEY` during migration so pre-envelope values remain readable. Envelopes are authenticated against tenant ID, service ID, purpose and secret name; copying ciphertext to another tenant or purpose makes decryption fail.

### Rotation

1. Add the new key to `NEXA_SECRET_KEYS` on every application and worker node.
2. Change `NEXA_SECRET_ACTIVE_KEY_ID` to the new key ID and restart workers.
3. For each tenant, run `php bin/rotate-managed-secrets.php --tenant=tenant-slug` from `espocrm`.
4. Confirm the `security.secret.rotation.completed` audit event and integration health.
5. Back up the database and key ring separately. Remove the old key only after every encrypted row reports the new key ID and rollback retention has elapsed.

Rotation is transactional per tenant. Readers accept every key in the ring, so existing sessions continue while rows are rewritten.

### Recovery

- Restore ciphertext and the matching key ring from separate backups.
- A database backup without its historical key material cannot recover secrets.
- If a key is exposed, add a replacement, rotate all tenants, revoke upstream credentials, then remove the compromised key.
- Never paste plaintext credentials into incident tickets, audit metadata, CLI arguments captured by shell history, or application logs.

## Controlled impersonation

Set `NEXA_PLATFORM_OPERATOR_IDS` to a comma-separated allowlist of platform operator user IDs. Tenant administrators are not automatically platform operators.

The lifecycle is deliberately two-person:

1. `POST api/v1/Nexa/security/impersonation/request` with `tenantSlug`, `targetUserId`, `reason`, and `durationMinutes` (5-60).
2. A different allowlisted operator calls `POST api/v1/Nexa/security/impersonation/{id}/approve`.
3. The requesting operator calls `POST api/v1/Nexa/security/impersonation/{id}/start` and uses the returned native token.
4. A persistent warning banner identifies the operator, shows remaining time, and provides **Exit**.
5. `POST api/v1/Nexa/security/impersonation/exit` revokes the impersonation token and restores a new operator token.

The target user's native tenant, service, role, record and field permissions remain authoritative. The operator gains no permission the target user does not have. The one-minute `ExpireImpersonationSessions` job revokes stale tokens, and native CRUD history is correlated into `nexa_audit_event` with both operator and effective user IDs.

Audit rows are append-only. They record the affected tenant/service, operator, effective user, action, target, result, correlation ID, time, client context, and redacted metadata. Emergency database maintenance must preserve and export the ledger rather than update or delete individual events.
