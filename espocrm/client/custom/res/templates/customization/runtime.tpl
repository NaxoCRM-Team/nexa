<div class="nexa-object-runtime" aria-live="polite">
    <header class="nexa-runtime-header">
        <div>
            <p class="nexa-runtime-eyebrow">CRM workspace</p>
            <h1 data-object-label>Records</h1>
            <p data-object-description></p>
        </div>
        <div class="nexa-runtime-header-actions">
            <button type="button" class="btn btn-default" data-action="back-to-list" hidden><span class="fas fa-arrow-left" aria-hidden="true"></span><span>Back to list</span></button>
            <button type="button" class="btn btn-primary" data-action="create-record"><span class="fas fa-plus" aria-hidden="true"></span><span data-create-label>New record</span></button>
        </div>
    </header>

    <section data-runtime-screen="list">
        <div class="nexa-runtime-toolbar">
            <label class="nexa-runtime-search"><span class="fas fa-search" aria-hidden="true"></span><span class="sr-only">Search records</span><input type="search" data-record-search placeholder="Search records" autocomplete="off"></label>
            <span class="nexa-runtime-total" data-record-total>0 records</span>
        </div>
        <div class="nexa-runtime-list" data-record-list><p class="nexa-loading-copy">Loading records...</p></div>
        <nav class="nexa-runtime-pagination" aria-label="Record pages" data-pagination></nav>
    </section>

    <section data-runtime-screen="form" hidden>
        <form class="nexa-runtime-form" data-record-form>
            <header><div><p class="nexa-runtime-eyebrow" data-form-eyebrow>New record</p><h2 data-form-title>Create record</h2></div></header>
            <div class="nexa-runtime-form-grid" data-record-fields></div>
            <footer><button type="submit" class="btn btn-primary"><span class="fas fa-save" aria-hidden="true"></span><span>Save record</span></button><button type="button" class="btn btn-default" data-action="cancel-form">Cancel</button></footer>
        </form>
    </section>

    <section data-runtime-screen="detail" hidden>
        <article class="nexa-runtime-detail">
            <header class="nexa-runtime-detail-header"><div class="nexa-runtime-avatar" data-record-avatar aria-hidden="true">R</div><div><p class="nexa-runtime-eyebrow" data-record-kind>Record</p><h2 data-record-name></h2><p data-record-updated></p></div><div class="nexa-runtime-detail-actions"><button type="button" class="btn btn-default" data-action="edit-record"><span class="fas fa-pen" aria-hidden="true"></span><span>Edit</span></button><button type="button" class="btn btn-danger" data-action="delete-record"><span class="fas fa-trash" aria-hidden="true"></span><span>Delete</span></button></div></header>
            <div class="nexa-runtime-detail-grid"><section class="nexa-runtime-properties"><h3>Record details</h3><dl data-detail-values></dl></section><aside class="nexa-runtime-associations"><h3>Associations</h3><div data-relationship-list></div></aside></div>
        </article>
    </section>

    <div class="nexa-runtime-dialog" data-dialog="associations" hidden role="dialog" aria-modal="true" aria-labelledby="nexa-runtime-association-title">
        <div class="nexa-runtime-dialog-backdrop" data-action="close-associations"></div>
        <section class="nexa-runtime-dialog-panel"><header><div><p class="nexa-runtime-eyebrow">Manage association</p><h2 id="nexa-runtime-association-title" data-association-title>Connect records</h2></div><button type="button" class="btn btn-icon" data-action="close-associations" aria-label="Close"><span class="fas fa-times" aria-hidden="true"></span></button></header><div data-association-workspace></div></section>
    </div>
</div>
