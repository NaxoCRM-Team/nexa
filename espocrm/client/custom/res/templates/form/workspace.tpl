<main class="nexa-form-workspace">
    <header class="nexa-form-heading">
        <div><p>Marketing</p><h1>Forms</h1><span>Create governed forms that write into the existing CRM customer lifecycle.</span></div>
        <button class="btn btn-primary" type="button" data-action="new-form"><span class="fas fa-plus" aria-hidden="true"></span>New form</button>
    </header>
    <nav class="nexa-form-tabs" aria-label="Forms workspace">
        <button type="button" class="is-active" data-action="switch-tab" data-tab="forms">Forms</button>
        <button type="button" data-action="switch-tab" data-tab="submissions">Submissions</button>
    </nav>
    <section class="nexa-form-state" data-form-state="loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><strong>Loading forms</strong></section>
    <section class="nexa-form-state" data-form-state="error" hidden><span class="fas fa-exclamation-circle" aria-hidden="true"></span><strong>Forms could not be loaded.</strong><button class="btn btn-default" data-action="reload">Try again</button></section>
    <div data-form-state="ready" hidden>
        <section data-form-tab="forms">
            <div class="nexa-form-toolbar">
                <label><span class="fas fa-search" aria-hidden="true"></span><input class="form-control" type="search" data-form-search placeholder="Search forms" aria-label="Search forms"></label>
                <select class="form-control" data-form-status aria-label="Filter forms by status"><option value="">All statuses</option><option value="published">Published</option><option value="draft">Draft</option><option value="archived">Archived</option></select>
                <strong data-form-count>0 forms</strong>
            </div>
            <div class="nexa-form-table-wrap" data-table-scroll="forms" role="region" aria-label="Forms" tabindex="0"><table class="table" data-workspace-table="forms"><thead><tr><th data-column="form"><button type="button" data-table-sort="form">Form</button></th><th data-column="status"><button type="button" data-table-sort="status">Status</button></th><th data-column="views"><button type="button" data-table-sort="views">Views</button></th><th data-column="submissions"><button type="button" data-table-sort="submissions">Submissions</button></th><th data-column="conversion"><button type="button" data-table-sort="conversion">Conversion</button></th><th data-column="records"><button type="button" data-table-sort="records">Records created</button></th><th data-column="modifiedAt"><button type="button" data-table-sort="modifiedAt">Last updated</button></th><th data-column="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody data-form-list></tbody></table></div>
            <div class="nexa-form-empty" data-form-empty hidden><span class="fas fa-file-alt" aria-hidden="true"></span><h2>Create your first form</h2><p>Collect customer information using CRM fields, duplicate checks and consent-aware settings.</p><button class="btn btn-primary" data-action="new-form">New form</button></div>
        </section>
        <section data-form-tab="submissions" hidden>
            <div class="nexa-form-toolbar"><label><span class="fas fa-search" aria-hidden="true"></span><input class="form-control" type="search" data-submission-search placeholder="Search submissions" aria-label="Search submissions"></label><strong data-submission-count>0 submissions</strong></div>
            <div class="nexa-form-table-wrap" data-table-scroll="submissions" role="region" aria-label="Form submissions" tabindex="0"><table class="table" data-workspace-table="submissions"><thead><tr><th data-column="submittedBy"><button type="button" data-table-sort="submittedBy">Submitted by</button></th><th data-column="formName"><button type="button" data-table-sort="formName">Form</button></th><th data-column="result"><button type="button" data-table-sort="result">CRM result</button></th><th data-column="createdAt"><button type="button" data-table-sort="createdAt">Date</button></th><th data-column="actions"><span class="sr-only">Actions</span></th></tr></thead><tbody data-submission-list></tbody></table></div>
        </section>
    </div>
    <div class="nexa-form-editor" data-form-editor hidden>
        <div class="nexa-form-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="nexa-form-editor-title">
            <header><div><p>Form builder</p><h2 id="nexa-form-editor-title" data-editor-title>New form</h2></div><button class="btn btn-icon" type="button" data-action="close-editor" title="Close"><span class="fas fa-times" aria-hidden="true"></span></button></header>
            <form data-form-editor-form novalidate>
                <div class="nexa-form-editor-grid">
                    <section class="nexa-form-config">
                        <h3>Form details</h3>
                        <label><span>Name <b>*</b></span><input class="form-control" name="name" maxlength="100" required><em data-form-error="name"></em></label>
                        <label><span>Description</span><textarea class="form-control" name="description" rows="2" maxlength="1000"></textarea></label>
                        <label><span>Public heading</span><input class="form-control" name="title" maxlength="80"></label>
                        <label><span>Form theme</span><select class="form-control" name="formTheme"></select></label>
                        <label><span>Text displayed on the form</span><textarea class="form-control" name="intro" rows="3" maxlength="2000"></textarea></label>
                        <h3>Available CRM fields</h3>
                        <label class="nexa-field-search"><span class="fas fa-search" aria-hidden="true"></span><input class="form-control" type="search" data-field-search placeholder="Search properties"></label>
                        <div class="nexa-field-catalog" data-field-catalog></div>
                    </section>
                    <section class="nexa-form-canvas">
                        <div><h3>Form fields</h3><span>Order the fields and choose which answers are required.</span></div>
                        <div data-selected-fields></div>
                        <div class="nexa-builder-preview"><p>Preview</p><div data-form-preview></div><button type="button" disabled>Submit</button></div>
                    </section>
                    <section class="nexa-form-settings">
                        <h3>Processing</h3>
                        <label><span>Lead source</span><input class="form-control" name="leadSource" value="Web Site"></label>
                        <label><span>Audience list</span><select class="form-control" name="targetListId"></select></label>
                        <label><span>Assign to team</span><select class="form-control" name="targetTeamId"></select></label>
                        <label class="nexa-check"><input type="checkbox" name="subscribeToTargetList">Add successful submissions to the selected audience</label>
                        <label class="nexa-check"><input type="checkbox" name="duplicateCheck" checked>Check Contacts and Leads for duplicates</label>
                        <label class="nexa-check"><input type="checkbox" name="captcha">Require CAPTCHA</label>
                        <label class="nexa-check"><input type="checkbox" name="progressiveProfiling">Use progressive profiling when visitor identity is known</label>
                        <h3>Consent</h3>
                        <label><span>Purpose</span><select class="form-control" name="consentPurposeId"></select></label>
                        <label><span>Channel</span><select class="form-control" name="consentChannel"><option value="">Select channel</option><option value="email">Email</option><option value="phone">Phone</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option><option value="linkedin">LinkedIn</option><option value="postal">Postal</option><option value="live_chat">Live chat</option></select></label>
                        <label><span>Consent statement</span><textarea class="form-control" name="consentLabel" rows="3" maxlength="500"></textarea></label>
                        <h3>After submission</h3>
                        <label><span>Text displayed after submission</span><textarea class="form-control" name="successMessage" rows="3" maxlength="2000"></textarea></label>
                        <label><span>Redirect URL</span><input class="form-control" type="url" name="redirectUrl" placeholder="https://example.com/thank-you"></label>
                        <label><span>Redirect delay</span><input class="form-control" type="number" name="redirectDelaySeconds" min="1" max="30" step="1" value="4"><small>Show the success message for this many seconds before redirecting.</small></label>
                        <label><span>Allowed website URLs</span><textarea class="form-control" name="frameAncestors" rows="2" placeholder="https://example.com&#10;https://www.example.com"></textarea><small>One website origin per line.</small></label>
                    </section>
                </div>
                <footer><div data-editor-status></div><button class="btn btn-default" type="button" data-action="close-editor">Cancel</button><button class="btn btn-default" type="submit"><span class="fas fa-save" aria-hidden="true"></span>Save draft</button><button class="btn btn-primary" type="button" data-action="publish-form"><span class="fas fa-paper-plane" aria-hidden="true"></span>Publish</button></footer>
            </form>
        </div>
    </div>
    <div class="nexa-form-confirm" data-submission-delete-dialog hidden>
        <div role="dialog" aria-modal="true" aria-labelledby="nexa-delete-submission-title">
            <header><h2 id="nexa-delete-submission-title">Delete submission?</h2><button class="btn btn-icon" type="button" data-action="cancel-submission-delete" title="Close"><span class="fas fa-times"></span></button></header>
            <section><p data-submission-delete-copy></p><div class="nexa-form-delete-warning" data-submission-lead-warning hidden><strong>Created Lead included</strong><span>The Lead created by this submission will also be moved to the recycle bin. Matched existing records are never deleted.</span></div></section>
            <footer><button class="btn btn-danger" type="button" data-action="confirm-submission-delete"><span class="fas fa-trash-alt"></span>Delete temporarily</button><button class="btn btn-default" type="button" data-action="cancel-submission-delete">Cancel</button></footer>
        </div>
    </div>
</main>
