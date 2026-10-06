# Marketing Email Designer

## Access

Open a Marketing Email draft and choose **Design blocks**. The isolated designer offers sections/columns, text, buttons, dividers and quotes. Blocks can be dragged to the canvas or inserted by click/Enter. Select a component to change its styles or properties; mobile users can switch the panel selector from Blocks to Settings. Desktop/mobile preview, outlines and undo/redo are available.

**Apply design** exports sanitized, CSS-inlined HTML back to the unsaved draft. **Cancel design** discards staged changes. Save the draft normally to create its native EmailTemplate version, then follow review/approval and scheduling. Neither opening the designer nor applying a design sends email. Attachments, sender settings and campaign ownership remain unchanged.

The designer uses pinned GrapesJS 0.23.6, newsletter preset 1.0.2 and DOMPurify 3.4.16. Vendor assets and licenses are included under `espocrm/client/custom/lib/email-designer`; it does not load a CDN or require Node on WAMP at runtime. Rebuild these assets after a reviewed dependency update:

```powershell
npm ci
npm run build:email-designer
npx playwright test tests/browser/marketing-block-designer.spec.js
```

## Boundaries

- Native EmailTemplate, MassEmail, queue, tenant/service isolation and backend approval behavior remain authoritative.
- GrapesJS storage/autosave is disabled. Staged content is held in the frame, not browser local storage or a separate template database.
- The host checks frame identity, origin and per-opening session before accepting exported content. Closing a draft invalidates the staged designer.
- Imports/exports are sanitized with DOMPurify. Script/form execution, remote images, external connections and base-URL rewriting are blocked by the designer Content Security Policy. Raw R2 keys, private Attachment URLs and credentials are never exposed as public image links.
- Existing HTML is parsed into GrapesJS components. Applying can normalize markup and CSS; cancelling preserves the original bytes. This is not a guarantee of pixel-identical rendering across every email client.
- The Email images picker searches public raster assets and independent email copies in the current tenant/service. Select a **Public asset**, then confirm **Publish and insert**, to create an immutable email copy in native Attachment storage. Only tenant administrators with the required scope and file-read permissions can publish; private assets are not silently made public. PNG, JPG, GIF and WebP are supported, up to 8 MB, 8192 pixels per dimension and 20 megapixels.
- Publication creates a new physical copy and an unpredictable public URL. It does not require a landing page. The copy remains published even if the source is archived, replaced or made Internal, and even if the unsaved design is cancelled. Identical active copies of the same source/checksum are reused. Publication records who acted and when. New copies follow the configured native storage driver, including tenant-prefixed Cloudflare R2 storage.
- **Revoke** stops the dedicated endpoint serving that copy and records the revoking administrator/time. The endpoint uses `no-store`, safe image MIME types, `nosniff`, tenant-token resolution and the existing persistent public rate limiter (600 requests/minute per tenant/service/client fingerprint). Previously cached copies held by email providers cannot be recalled. Original files are not deleted. Revoked bytes are retained privately for audit rather than purged by this slice.
- Previews use authenticated bytes/data URLs; export restores the public URL without exposing preview data or private storage identifiers. Publication identity, not identical preview bytes, determines each image URL. Reopening restores hosted previews without contacting external image hosts. Save, review approval, scheduling, administrator test sends and production dispatch verify the managed image manifest/checksum. Revoked or corrupt copies fail before production queue reservation/provider submission; historical version restoration also validates its original image evidence.
- Older saved landing-page image URLs remain preview-compatible, but new selection favors independent copies. Those legacy URLs still depend on their source page and asset. Other remote images may remain in exported HTML but are blocked in the editing canvas. Production recipients need a publicly reachable HTTPS site URL; localhost URLs are only useful for local testing. External deliverability/public ingress acceptance remains pending.
- Direct upload of private images from the designer remains pending. Upload and manage assets through the existing Asset Library, explicitly choose Public there, and then confirm a separate email publication.
- Dynamic content, programmable/single-send APIs, visual automation and experiments are not completed by this slice.

## Verification

The real vendored editor runs in Playwright, rather than an editor double. Tests cover mouse drag, click insertion, undo/redo, mobile settings, staged cancel/apply, native personalization-token preservation, sanitized CSS-inlined export, attachment preservation, remote-request blocking and no local-storage autosave across five viewports. Screenshots are generated under ignored `test-results/` for desktop/mobile review. Existing native-editor host tests remain separate.

`tests/tenant/TenantMarketingDesignerImagesTest.php` retains legacy landing-page preview coverage. `TenantMarketingImagePublicationTest.php` verifies independent snapshots, confirmation, read/write permissions, tenant/service boundaries, native public response headers, source replacement independence, corruption, revocation and actor/time evidence. `TenantMarketingImageDispatchTest.php` verifies native save/approval, scheduling and background-worker preflight failure without allowance charges/provider submissions. Synthetic fixture records are rolled back and uploaded copies cleaned up. Browser tests cover publication cancellation/Escape, identical-byte image URL identity, live search, actual canvas decoding, export/reopen and revocation.

Migration `0065_marketing_image_publications.sql` is additive and uses the existing shared migration runner. Apply it with your configured local database credentials, then rebuild, clear cache and update the app timestamp. The new public `NexaMarketingImage` entry point serves only published snapshots; it never accepts an arbitrary Attachment ID.
