# Phase 4 Native Capability Audit

Phase 4 extends EspoCRM where its existing model is suitable. Nexa adds a custom capability only when the native product has no equivalent business boundary.

## Retain

- Contacts remain the customer identity used for marketing eligibility.
- Target Lists remain the native audience membership model, including per-member opt-out state.
- Lead Capture remains the forms foundation, including field selection, duplicate checks, confirmation, CAPTCHA, redirects, embedding controls and Target List subscription.
- Documents, Document Folders and Attachments remain the asset metadata and relationship foundation.
- Native email and phone opt-out fields remain active delivery guards.

## Extend And Redesign

- Contact marketing status and channel restrictions gain purpose, evidence, policy version and immutable decision history.
- Lead Capture will receive a tenant-facing form builder, publishing workflow and analytics without replacing its entity or submission path.
- Target Lists will receive static and dynamic audience rules without creating a competing list entity.
- Documents and Attachments will receive a modern tenant asset library and retain the existing tenant-scoped Cloudflare R2 storage path.

## New Capability

- Consent purposes, consent events and current consent state are new because EspoCRM opt-out flags do not preserve purpose-specific legal evidence.
- Landing pages and reusable content blocks are new because no native landing-page publishing model exists.
- Cookie categories, banner versions, regional rules and browser consent receipts are new because no native cookie-governance model exists.

## Excluded From This Workstream

Campaign management and marketing email are owned by the parallel Phase 4 workstream. Consent and audience APIs expose stable boundaries for that work, but do not duplicate its editors, delivery engine or analytics.
