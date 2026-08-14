<div class="nexa-export-workspace">
    <header class="nexa-export-page-header">
        <div>
            <h2>Import and Export</h2>
            <p>Move contact data into or out of this workspace.</p>
        </div>
        <button class="btn btn-default" data-action="back"><span class="fas fa-arrow-left" aria-hidden="true"></span> Back to Contacts</button>
    </header>

    <section class="nexa-export-import-band" aria-labelledby="nexa-import-heading">
        <div>
            <h4 id="nexa-import-heading">Import contact data</h4>
            <p>Validate CSV or Excel files before tenant-scoped records are created.</p>
        </div>
        <button class="btn btn-default" data-action="import"><span class="fas fa-file-import" aria-hidden="true"></span> Go to import</button>
    </section>

    <section class="nexa-export-guidance" aria-labelledby="nexa-export-heading">
        <span class="fas fa-shield-alt" aria-hidden="true"></span>
        <div>
            <h3 id="nexa-export-heading">Export</h3>
            <p>Exports respect the current user's record access, field permissions, active filters, tenant and service. Large exports are processed in the background automatically.</p>
        </div>
    </section>

    <section class="nexa-export-audit" aria-labelledby="nexa-export-audit-heading">
        <div class="nexa-export-audit-heading">
            <div>
                <h3 id="nexa-export-audit-heading">Export Audit</h3>
                <p>Completed exports from this workspace are listed below.</p>
            </div>
            <button class="btn btn-default btn-icon" data-action="refresh" title="Refresh exports" aria-label="Refresh exports"><span class="fas fa-sync-alt" aria-hidden="true"></span></button>
        </div>
        <div class="table-responsive">
            <table class="table nexa-export-table">
                <thead><tr><th>Name</th><th>Source</th><th>Exported records</th><th>User</th><th>Date</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody data-name="auditRows"></tbody>
            </table>
        </div>
    </section>
</div>
