<div class="nexa-contact-import nexa-account-import">
    <div class="page-header nexa-import-header">
        <div>
            <h3><a href="{{accountUrl}}">Accounts</a><span class="breadcrumb-separator"><span></span></span>Import Accounts</h3>
            <p class="text-muted">Validate company data before creating tenant-scoped account records.</p>
        </div>
        <button class="btn btn-default" data-action="cancel"><span class="fas fa-arrow-left" aria-hidden="true"></span> Back to Accounts</button>
    </div>

    <nav class="nexa-import-steps" aria-label="Import progress">
        <span class="is-complete"><b>1</b> Upload</span>
        <span data-name="stepValidate"><b>2</b> Validate</span>
        <span data-name="stepImport"><b>3</b> Import</span>
    </nav>

    <section class="nexa-import-band" aria-labelledby="account-import-upload-title">
        <div class="nexa-import-band-heading">
            <div>
                <h4 id="account-import-upload-title">Upload account file</h4>
                <p>All .csv, .xlsx and .xls files are supported up to 65 MB.</p>
            </div>
            <button class="btn btn-default" data-action="downloadTemplate">
                <span class="fas fa-download" aria-hidden="true"></span> Download template
            </button>
        </div>

        <div class="nexa-import-value-notice" role="note" aria-labelledby="account-import-values-title">
            <span class="fas fa-exclamation-triangle" aria-hidden="true"></span>
            <div>
                <strong id="account-import-values-title">Check controlled values before uploading</strong>
                <p><b>Account Type:</b> Customer, Investor, Partner or Reseller.</p>
                <p><b>Lifecycle Stage:</b> Subscriber, Lead, Marketing Qualified Lead, Sales Qualified Lead, Opportunity, Customer, Evangelist or Other.</p>
                <p><b>Lead Status:</b> New, Open, In Progress, Open Deal, Unqualified, Attempted to Contact, Connected or Bad Timing.</p>
                <p class="nexa-import-value-help">Industry and currency must be enabled in this workspace. Websites without a scheme are converted to https://. Any unsupported value is highlighted before import.</p>
            </div>
        </div>

        <div class="nexa-import-controls">
            <label class="nexa-row-limit">
                <span>Maximum rows for this import</span>
                <input class="form-control" type="number" min="1" max="100000" value="{{defaultRowLimit}}" data-name="rowLimit">
                <small>Choose up to {{maxRowLimit}} rows.</small>
            </label>

            <div class="nexa-file-drop" data-name="dropZone">
                <input class="sr-only" type="file" accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" data-name="file" aria-label="Choose Account import file">
                <span class="fas fa-file-csv" aria-hidden="true"></span>
                <strong>Drop a CSV or Excel file here</strong>
                <span>or</span>
                <button class="btn btn-default" data-action="chooseFile">Choose file</button>
                <small data-name="fileName">No file selected</small>
            </div>
        </div>

        <div class="nexa-import-actions">
            <span></span>
            <button class="btn btn-primary" data-action="validate" disabled>
                <span class="fas fa-check-circle" aria-hidden="true"></span> Validate and preview
            </button>
        </div>
    </section>

    <div class="nexa-import-busy hidden" data-name="busy" role="status" aria-live="polite">
        <span class="fas fa-spinner fa-spin" aria-hidden="true"></span><span data-name="busyText"></span>
    </div>
    <div class="alert hidden" data-name="result" role="status" aria-live="polite"></div>
    <p class="nexa-import-summary hidden" data-name="summary"></p>
    <div class="alert hidden" data-name="existingMatch" role="status"></div>
    <ul class="nexa-import-errors hidden" data-name="errors" aria-label="Account import validation errors"></ul>

    <section class="nexa-import-preview hidden" data-name="preview" aria-labelledby="account-import-preview-title">
        <div class="nexa-import-band-heading">
            <div>
                <h4 id="account-import-preview-title">Account preview</h4>
                <p>Twenty rows are shown per page. Existing companies are checked within this tenant only.</p>
            </div>
            <button class="btn btn-primary" data-action="import" disabled>
                <span class="fas fa-file-import" aria-hidden="true"></span> Import accounts
            </button>
        </div>
        <div class="table-responsive" data-name="previewTable">
            <table class="table table-hover">
                <thead><tr><th>Company</th><th>Website</th><th>Industry</th><th>Type</th><th>Revenue</th><th>Country</th></tr></thead>
                <tbody data-name="previewBody"></tbody>
            </table>
        </div>
        <nav class="nexa-preview-pagination hidden" data-name="previewPagination" aria-label="Account preview pages">
            <button class="btn btn-default" data-action="previousPreviewPage" disabled><span class="fas fa-chevron-left" aria-hidden="true"></span> Previous</button>
            <span data-name="previewPageStatus" aria-live="polite">Page 1 of 1</span>
            <button class="btn btn-default" data-action="nextPreviewPage" disabled>Next <span class="fas fa-chevron-right" aria-hidden="true"></span></button>
        </nav>
    </section>
</div>
