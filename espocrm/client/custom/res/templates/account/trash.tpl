<div class="nexa-restore-workspace">
    <header class="nexa-restore-header">
        <div><h2>Restore account records</h2><p>Review and restore Accounts deleted during the two-month recovery window.</p></div>
        <button class="btn btn-default" data-action="back"><span class="fas fa-arrow-left" aria-hidden="true"></span> Back to Accounts</button>
    </header>

    <section class="nexa-restore-filters" aria-label="Deleted account filters">
        <label class="nexa-restore-search"><span>Search records</span><span class="nexa-restore-input-wrap"><span class="fas fa-search" aria-hidden="true"></span><input class="form-control" type="search" data-name="search" placeholder="Search name or website" autocomplete="off"></span></label>
        <label><span>Deleted from</span><input class="form-control" type="date" data-name="dateFrom"></label>
        <label><span>Deleted to</span><input class="form-control" type="date" data-name="dateTo"></label>
        <label><span>Deleted by</span><select class="form-control" data-name="deletedBy"><option value="">All users</option></select></label>
        <button class="btn btn-link nexa-restore-clear" data-action="clearFilters">Clear filters</button>
    </section>

    <section class="nexa-restore-results" aria-labelledby="nexa-account-restore-heading">
        <div class="nexa-restore-results-heading">
            <div><h3 id="nexa-account-restore-heading">Deleted accounts</h3><p data-name="resultCount">Loading records...</p></div>
            <div class="nexa-restore-actions">
                <button class="btn btn-danger" data-action="purge" disabled hidden><span class="fas fa-trash-alt" aria-hidden="true"></span> Permanently delete</button>
                <button class="btn btn-primary" data-action="restore" disabled><span class="fas fa-undo-alt" aria-hidden="true"></span> Restore selected</button>
            </div>
        </div>
        <div class="table-responsive nexa-restore-table-wrap">
            <table class="table nexa-restore-table">
                <thead><tr><th class="nexa-restore-check"><input type="checkbox" data-name="selectAll" aria-label="Select all visible records"></th><th>Account</th><th>Deleted by</th><th>Date deleted</th></tr></thead>
                <tbody data-name="records"><tr><td colspan="4" class="nexa-restore-empty">Loading deleted accounts...</td></tr></tbody>
            </table>
        </div>
    </section>
</div>
