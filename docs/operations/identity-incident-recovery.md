# Identity Incident Recovery

Use this runbook when an OIDC client secret, SAML certificate, authenticator, recovery code, provider subject or active session may be compromised.

## Contain

1. Disable the affected row in `nexa_identity_provider`; do not disable unrelated tenants.
2. Revoke the client secret or signing certificate at the identity provider.
3. Inactivate the affected user's auth tokens and preserve authentication and `nexa_audit_event` records.
4. If MFA is involved, disable the user's old factor and invalidate all unused recovery codes.
5. Record the tenant, provider, time window, operator and reason in the incident record.

## Recover

1. Rotate the provider credential and update it through `configure-identity-provider.php`; plaintext secrets must never be written to SQL or Git.
2. For SAML, import the replacement public certificate and confirm the old certificate no longer validates.
3. Require the user to enroll a new MFA factor and generate a new set of recovery codes.
4. Re-enable the provider only after callback, replay, expiry, lockout and audit checks pass.
5. Notify affected tenant administrators according to the security response policy.

## Master-key rotation

Use the versioned key-ring and rotation procedure in [security-governance.md](security-governance.md). Keep old and new keys available during rotation; never rotate by replacing `NEXA_AUTH_SECRET_KEY` alone because pre-rotation credentials would become unreadable.

## Lost access

A tenant administrator who loses an authenticator may use one unused recovery code. If none remains, a platform operator must verify identity through the approved support process, reset the MFA enrollment, revoke all active sessions, and audit the privileged recovery. Support must never reveal whether an account exists to an unauthenticated requester.
