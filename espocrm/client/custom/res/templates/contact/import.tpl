<div class="nexa-contact-import">
    <div class="page-header nexa-import-header">
        <div>
            <h3><a href="{{contactUrl}}">Contacts</a><span class="breadcrumb-separator"><span></span></span>Import Contacts</h3>
            <p class="text-muted">Validate a CSV file before creating tenant-scoped contact records.</p>
        </div>
        <button class="btn btn-default" data-action="cancel"><span class="fas fa-arrow-left" aria-hidden="true"></span> Back to Contacts</button>
    </div>

    <nav class="nexa-import-steps" aria-label="Import progress">
        <span class="is-complete"><b>1</b> Upload</span>
        <span data-name="stepValidate"><b>2</b> Validate</span>
        <span data-name="stepImport"><b>3</b> Import</span>
    </nav>

    <section class="nexa-import-band" aria-labelledby="contact-import-upload-title">
        <div class="nexa-import-band-heading">
            <div>
                <h4 id="contact-import-upload-title">Upload contact CSV</h4>
                <p>Use the Nexa template. CSV files can be up to 65 MB.</p>
            </div>
            <button class="btn btn-default" data-action="downloadTemplate">
                <span class="fas fa-download" aria-hidden="true"></span> Download template
            </button>
        </div>

        <div class="nexa-import-controls">
            <label class="nexa-row-limit">
                <span>Maximum rows for this import</span>
                <input class="form-control" type="number" min="1" max="100000" value="{{defaultRowLimit}}" data-name="rowLimit">
                <small>Choose up to {{maxRowLimit}} rows.</small>
            </label>

            <div class="nexa-file-drop" data-name="dropZone">
                <input class="sr-only" type="file" accept=".csv,text/csv" data-name="file" aria-label="Choose Contact CSV file">
                <span class="fas fa-file-csv" aria-hidden="true"></span>
                <strong>Drop a CSV file here</strong>
                <span>or</span>
                <button class="btn btn-default" data-action="chooseFile">Choose file</button>
                <small data-name="fileName">No file selected</small>
            </div>
        </div>

        <div class="nexa-import-actions">
            <button class="btn btn-primary" data-action="validate" disabled>
                <span class="fas fa-check-circle" aria-hidden="true"></span> Validate and preview
            </button>
        </div>
    </section>

    <div class="nexa-import-busy hidden" data-name="busy" role="status" aria-live="polite">
        <span class="fas fa-spinner fa-spin" aria-hidden="true"></span>
        <span data-name="busyText"></span>
    </div>
    <div class="alert hidden" data-name="result" role="status" aria-live="polite"></div>
    <p class="nexa-import-summary hidden" data-name="summary"></p>
    <div class="alert hidden" data-name="accountMatch" role="status"></div>
    <ul class="nexa-import-errors hidden" data-name="errors" aria-label="CSV validation errors"></ul>

    <section class="nexa-import-preview hidden" data-name="preview" aria-labelledby="contact-import-preview-title">
        <div class="nexa-import-band-heading">
            <div>
                <h4 id="contact-import-preview-title">Contact preview</h4>
                <p>The first 20 rows are shown. Existing contacts are checked by the standard duplicate rules.</p>
            </div>
            <button class="btn btn-primary" data-action="import" disabled>
                <span class="fas fa-file-import" aria-hidden="true"></span> Import contacts
            </button>
        </div>
        <div class="table-responsive" data-name="previewTable">
            <table class="table table-hover">
                <thead><tr><th>First name</th><th>Last name</th><th>Email</th><th>Phone</th><th>Account</th><th>Source</th></tr></thead>
                <tbody data-name="previewBody"></tbody>
            </table>
        </div>
        <nav class="nexa-preview-pagination hidden" data-name="previewPagination" aria-label="Contact preview pages">
            <button class="btn btn-default" data-action="previousPreviewPage" disabled>
                <span class="fas fa-chevron-left" aria-hidden="true"></span> Previous
            </button>
            <span data-name="previewPageStatus" aria-live="polite">Page 1 of 1</span>
            <button class="btn btn-default" data-action="nextPreviewPage" disabled>
                Next <span class="fas fa-chevron-right" aria-hidden="true"></span>
            </button>
        </nav>
    </section>
</div>
