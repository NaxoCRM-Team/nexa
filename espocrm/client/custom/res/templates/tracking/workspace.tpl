<main class="nexa-tracking-workspace" aria-labelledby="nexa-tracking-title">
    <header class="nexa-tracking-header"><div><p>Data &amp; Integrations</p><h1 id="nexa-tracking-title">Tracking &amp; Events</h1><span>Connect approved websites to the consent-aware customer event timeline.</span></div><button class="btn btn-primary" type="button" data-action="add-source"><span class="fas fa-plus"></span>Add website</button></header>
    <section class="nexa-tracking-notice"><span class="fas fa-shield-alt"></span><div><strong>Browser events never choose a CRM identity.</strong><p>Anonymous visits are attached to a Contact only through a separately verified form, authenticated session, OAuth identity, or server-side action.</p></div></section>
    <section data-tracking-state="loading" class="nexa-tracking-state"><span class="fas fa-circle-notch fa-spin"></span><p>Loading tracking sources...</p></section>
    <section data-tracking-state="error" class="nexa-tracking-state" hidden><span class="fas fa-exclamation-circle"></span><p>Tracking sources could not be loaded.</p><button class="btn btn-default" type="button" data-action="reload">Try again</button></section>
    <section data-tracking-state="ready" hidden>
        <div class="nexa-tracking-toolbar"><h2>Website sources</h2><strong data-source-total>0 sources</strong></div>
        <div class="nexa-tracking-list" data-source-list></div>
        <section class="nexa-retention-panel" aria-labelledby="nexa-retention-title">
            <header>
                <div><p>Data governance</p><h2 id="nexa-retention-title">Event retention</h2><span>Apply one policy to behavior events owned by this workspace.</span></div>
                <span class="nexa-retention-hold-status" data-retention-hold-status hidden><span class="fas fa-lock" aria-hidden="true"></span>Legal hold active</span>
            </header>
            <div class="nexa-retention-metrics">
                <span><b data-retention-total>0</b>Stored events</span>
                <span><b data-retention-identified>0</b>Identified</span>
                <span><b data-retention-anonymous>0</b>Anonymous</span>
                <span><b data-retention-eligible>0</b>Due for purge</span>
                <span><b data-retention-oldest>No events</b>Oldest event</span>
            </div>
            <form data-retention-form novalidate>
                <div class="nexa-retention-periods">
                    <label><span>Identified customer events</span><div><input class="form-control" type="number" name="identifiedDays" min="90" max="2555" required><small>days</small></div><em>90 days to 7 years</em></label>
                    <label><span>Anonymous visitor events</span><div><input class="form-control" type="number" name="anonymousDays" min="30" max="730" required><small>days</small></div><em>30 days to 2 years</em></label>
                    <label><span>Completed replay requests</span><div><input class="form-control" type="number" name="replayDays" min="30" max="365" required><small>days</small></div><em>30 days to 1 year</em></label>
                </div>
                <label class="nexa-retention-hold"><input type="checkbox" name="legalHold"><span><strong>Place behavior data under legal hold</strong><small>Scheduled and manual purges remain blocked until the hold is removed.</small></span></label>
                <label data-retention-reason hidden><span>Legal-hold reason</span><textarea class="form-control" name="legalHoldReason" maxlength="500" rows="3" placeholder="Matter, request or regulatory reason"></textarea><small>Required while the hold is active and recorded in the security audit ledger.</small></label>
                <footer><button class="btn btn-primary" type="submit"><span class="fas fa-save" aria-hidden="true"></span>Save policy</button><button class="btn btn-default nexa-retention-purge" type="button" data-action="purge-retention"><span class="fas fa-broom" aria-hidden="true"></span>Purge due data</button><span data-retention-updated></span></footer>
            </form>
        </section>
    </section>
    <div class="nexa-tracking-modal" data-source-modal hidden><section role="dialog" aria-modal="true" aria-labelledby="nexa-source-dialog-title"><header><div><p>Website collector</p><h2 id="nexa-source-dialog-title" data-source-dialog-title>Add tracking source</h2></div><button class="btn btn-icon" type="button" data-action="close-source" aria-label="Close"><span class="fas fa-times"></span></button></header><form data-source-form novalidate><input type="hidden" name="id"><label><span>Source name</span><input class="form-control" name="name" maxlength="120" required placeholder="Main company website"></label><label><span>Approved website origins</span><textarea class="form-control" name="allowedOrigins" rows="4" required placeholder="https://www.example.com&#10;http://localhost:3000"></textarea><small>One exact origin per line. Wildcards are not accepted; HTTP is restricted to local development.</small></label><div class="nexa-tracking-form-grid"><label><span>Consent mode</span><select class="form-control" name="integrationMode"><option value="managed">Nexa cookie consent</option><option value="external">Existing consent platform</option><option value="necessary_only">Necessary events only</option></select></label><label><span>Status</span><select class="form-control" name="status"><option value="active">Active</option><option value="paused">Paused</option></select></label></div><label data-source-key-row hidden><span>Public source key</span><div class="nexa-tracking-key"><input class="form-control" name="publicKey" readonly><button class="btn btn-default" type="button" data-action="rotate-key" data-rotate-key><span class="fas fa-sync-alt"></span>Rotate</button></div><small>Rotation immediately invalidates the previous website embed code.</small></label><footer><button class="btn btn-primary" type="submit"><span class="fas fa-save"></span>Save source</button><button class="btn btn-default" type="button" data-action="close-source">Cancel</button></footer></form></section></div>
</main>
