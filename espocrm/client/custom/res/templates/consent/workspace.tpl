<main class="nexa-consent-workspace" aria-labelledby="nexa-consent-title">
    <header class="nexa-consent-header">
        <div><p>Data &amp; Integrations</p><h1 id="nexa-consent-title">Consent &amp; Privacy</h1><span>Keep communication eligibility explainable without creating a second customer record.</span></div>
        <button class="btn btn-primary" type="button" data-action="add-purpose" data-purpose-action><span class="fas fa-plus" aria-hidden="true"></span>Add purpose</button>
    </header>

    <section data-consent-state="loading" class="nexa-consent-state"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><p>Loading consent governance...</p></section>
    <section data-consent-state="error" class="nexa-consent-state" hidden><span class="fas fa-exclamation-circle" aria-hidden="true"></span><p>Consent governance could not be loaded.</p><button class="btn btn-default" type="button" data-action="reload">Try again</button></section>

    <div data-consent-state="ready" hidden>
        <nav class="nexa-consent-tabs" aria-label="Consent settings">
            <button type="button" class="is-active" data-action="switch-consent-view" data-view="communications"><span class="fas fa-user-shield" aria-hidden="true"></span>Communication consent</button>
            <button type="button" data-action="switch-consent-view" data-view="cookies"><span class="fas fa-cookie-bite" aria-hidden="true"></span>Website cookies</button>
        </nav>
        <div data-consent-view="communications">
        <section class="nexa-consent-summary" aria-label="Consent eligibility summary">
            <article><span class="fas fa-users" aria-hidden="true"></span><div><small>Total contacts</small><strong data-summary="totalContacts">0</strong></div></article>
            <article><span class="fas fa-bullhorn" aria-hidden="true"></span><div><small>Marketing contacts</small><strong data-summary="marketingContacts">0</strong></div></article>
            <article class="is-positive"><span class="fas fa-check-circle" aria-hidden="true"></span><div><small>Eligible</small><strong data-summary="eligible">0</strong></div></article>
            <article class="is-warning"><span class="fas fa-exclamation-triangle" aria-hidden="true"></span><div><small>Needs evidence review</small><strong data-summary="reviewRequired">0</strong></div></article>
            <article class="is-danger"><span class="fas fa-ban" aria-hidden="true"></span><div><small>Suppressed</small><strong data-summary="suppressed">0</strong></div></article>
        </section>

        <section class="nexa-consent-grid">
            <div class="nexa-consent-panel">
                <header><div><p>Governance</p><h2>Communication purposes</h2><span>Purposes explain why customer data is used. Channels and legal basis are recorded with every decision.</span></div></header>
                <div class="nexa-purpose-list" data-purpose-list></div>
            </div>

            <div class="nexa-consent-panel nexa-decision-panel">
                <header><div><p>Evidence</p><h2>Record a consent decision</h2><span>Search for a Contact, then record the source and evidence supplied by that person.</span></div></header>
                <label class="nexa-contact-search"><span>Contact</span><div><span class="fas fa-search" aria-hidden="true"></span><input class="form-control" type="search" data-contact-search placeholder="Search by name or email" autocomplete="off"></div></label>
                <div class="nexa-contact-results" data-contact-results hidden></div>
                <div class="nexa-selected-contact" data-selected-contact hidden></div>
                <form data-decision-form novalidate hidden>
                    <div class="nexa-form-grid">
                        <label><span>Purpose</span><select class="form-control" name="purposeId" required></select></label>
                        <label><span>Channel</span><select class="form-control" name="channel" required></select></label>
                        <label><span>Decision <b aria-hidden="true">*</b></span><select class="form-control" name="status" required><option value="granted">Granted</option><option value="denied">Denied</option><option value="withdrawn">Withdrawn</option><option value="not_required">Not required</option></select></label>
                        <label><span>Legal basis</span><select class="form-control" name="legalBasis" required></select></label>
                        <label><span>Evidence source <b aria-hidden="true">*</b></span><select class="form-control" name="source" required><option value="manual">Recorded by staff</option><option value="form">Form submission</option><option value="import">Imported evidence</option><option value="api">Connected system</option><option value="preference_center">Preference centre</option></select></label>
                        <label><span>Expires at <small>Optional</small></span><input class="form-control" type="datetime-local" name="expiresAt"></label>
                    </div>
                    <label><span>Evidence note <b data-evidence-required aria-hidden="true">*</b></span><small data-evidence-help>Required when staff record a granted decision.</small><textarea class="form-control" name="evidenceNote" rows="3" maxlength="1000" placeholder="Describe how and where the decision was captured" aria-describedby="consent-evidence-error"></textarea><em id="consent-evidence-error" data-field-error="evidenceNote" role="alert"></em></label>
                    <footer><button class="btn btn-primary" type="submit"><span class="fas fa-shield-alt" aria-hidden="true"></span>Record decision</button><button class="btn btn-default" type="button" data-action="clear-contact">Cancel</button></footer>
                </form>
            </div>
        </section>

        <section class="nexa-consent-panel nexa-consent-history">
            <header><div><p>Audit history</p><h2>Consent decisions</h2><span>Corrections append a new version. Voided evidence remains retained with its reason, actor and timestamp.</span></div></header>
            <div class="nexa-consent-history-tools">
                <label class="nexa-history-search"><span class="fas fa-search" aria-hidden="true"></span><input class="form-control" type="search" data-history-search placeholder="Search Contact, purpose or staff" aria-label="Search consent history"></label>
                <select class="form-control" data-history-purpose aria-label="Filter by purpose"><option value="">All purposes</option></select>
                <select class="form-control" data-history-channel aria-label="Filter by channel"><option value="">All channels</option></select>
                <select class="form-control" data-history-status aria-label="Filter by decision"><option value="">All decisions</option><option value="granted">Granted</option><option value="denied">Denied</option><option value="withdrawn">Withdrawn</option><option value="not_required">Not required</option></select>
                <label class="nexa-history-voided"><input type="checkbox" data-history-voided>Show voided</label>
                <strong data-history-count>0 decisions</strong>
            </div>
            <div class="nexa-consent-table-wrap"><table class="table"><thead><tr><th><button type="button" data-history-sort="contactName">Contact</button></th><th><button type="button" data-history-sort="purposeName">Purpose</button></th><th><button type="button" data-history-sort="channel">Channel</button></th><th><button type="button" data-history-sort="status">Decision</button></th><th><button type="button" data-history-sort="source">Source</button></th><th><button type="button" data-history-sort="actorName">Recorded by</button></th><th><button type="button" data-history-sort="occurredAt">Date</button></th><th>Actions</th></tr></thead><tbody data-consent-history></tbody></table></div>
        </section>
        </div>

        <section class="nexa-cookie-workspace" data-consent-view="cookies" hidden>
            <div class="nexa-cookie-summary">
                <article><span class="fas fa-receipt" aria-hidden="true"></span><div><small>Total receipts</small><strong data-cookie-summary="totalReceipts">0</strong></div></article>
                <article><span class="fas fa-calendar-alt" aria-hidden="true"></span><div><small>Last 30 days</small><strong data-cookie-summary="last30Days">0</strong></div></article>
                <article><span class="fas fa-broadcast-tower" aria-hidden="true"></span><div><small>Publishing</small><strong data-cookie-publish-state>Draft</strong></div></article>
            </div>
            <div class="nexa-cookie-layout">
                <form class="nexa-consent-panel nexa-cookie-form" data-cookie-form novalidate>
                    <header><div><p>Website privacy</p><h2>Cookie banner</h2><span>Configure the notice visitors see and preserve a versioned receipt of every choice.</span></div></header>
                    <div class="nexa-cookie-form-body">
                        <div class="nexa-form-grid">
                            <label><span>Banner name <b>*</b></span><input class="form-control" name="name" maxlength="120" required><em data-cookie-error="name"></em></label>
                            <label><span>Policy version <b>*</b></span><input class="form-control" name="policyVersion" maxlength="40" required><em data-cookie-error="policyVersion"></em></label>
                            <label><span>Heading <b>*</b></span><input class="form-control" name="heading" maxlength="160" required><em data-cookie-error="heading"></em></label>
                            <label><span>Privacy notice URL</span><input class="form-control" type="url" name="privacyNoticeUrl" placeholder="https://example.com/privacy"><em data-cookie-error="privacyNoticeUrl"></em></label>
                        </div>
                        <label><span>Notice <b>*</b></span><textarea class="form-control" name="message" rows="3" maxlength="1000" required></textarea><em data-cookie-error="message"></em></label>
                        <div class="nexa-form-grid">
                            <label><span>Integration mode</span><select class="form-control" name="integrationMode"><option value="managed">Nexa-managed banner</option><option value="existing_banner">Use existing website banner</option></select><small data-cookie-mode-help></small></label>
                            <label><span>Regional coverage</span><select class="form-control" name="regionMode"><option value="global">All visitors</option><option value="eu_uk">EU and United Kingdom</option><option value="custom">Selected regions</option></select></label>
                            <label data-cookie-regions hidden><span>Country or region codes</span><input class="form-control" name="regions" placeholder="GB, IE, FR"><small>Use two-letter codes separated by commas.</small><em data-cookie-error="regions"></em></label>
                            <label><span>Position</span><select class="form-control" name="position"><option value="bottom">Full width bottom</option><option value="bottom_left">Bottom left</option><option value="bottom_right">Bottom right</option></select></label>
                            <label><span>Language</span><input class="form-control" name="locale" maxlength="12" value="en"></label>
                        </div>
                        <div class="nexa-cookie-colors">
                            <label><span>Action colour</span><input type="color" name="primaryColor"></label>
                            <label><span>Background</span><input type="color" name="backgroundColor"></label>
                            <label><span>Text</span><input type="color" name="textColor"></label>
                            <label><input type="checkbox" name="showReject">Show reject button</label>
                            <label><input type="checkbox" name="isPublished">Published</label>
                        </div>
                        <section class="nexa-cookie-categories">
                            <header><div><h3>Cookie categories</h3><span>Necessary cookies stay enabled. Add optional categories only when the website uses them.</span></div><button type="button" class="btn btn-default" data-action="add-cookie-category"><span class="fas fa-plus"></span>Add category</button></header>
                            <div data-cookie-categories></div>
                            <em data-cookie-error="categories"></em>
                        </section>
                    </div>
                    <footer><button class="btn btn-primary" type="submit"><span class="fas fa-save"></span>Save cookie settings</button></footer>
                </form>
                <aside>
                    <section class="nexa-consent-panel nexa-cookie-preview-panel"><header><div><p>Preview</p><h2>Visitor experience</h2></div></header><div class="nexa-cookie-preview" data-cookie-preview></div></section>
                    <section class="nexa-consent-panel nexa-cookie-install"><header><div><p>Installation</p><h2>Website embed code</h2><span data-cookie-install-help>Add this once before the closing body tag on the tenant website.</span></div></header><div><textarea class="form-control" data-cookie-embed readonly rows="4"></textarea><button type="button" class="btn btn-default" data-action="copy-cookie-embed"><span class="fas fa-copy"></span>Copy code</button><div class="nexa-cookie-sync-example" data-cookie-sync-example hidden><strong>Connect the existing banner</strong><p>Call this whenever its visitor choice changes.</p><pre>window.NexaConsent.sync({
  choice: 'custom',
  categories: {
    necessary: true,
    preferences: false,
    analytics: true,
    advertising: false
  }
});</pre></div></div></section>
                </aside>
            </div>
        </section>
    </div>
    <div class="nexa-consent-modal" data-purpose-modal hidden></div>
    <div class="nexa-consent-modal" data-void-modal hidden></div>
</main>
