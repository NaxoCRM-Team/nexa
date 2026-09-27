<section class="nexa-asset-workspace">
    <header class="nexa-asset-header">
        <div><span class="nexa-asset-eyebrow">Marketing content</span><h1>Content &amp; Assets</h1><p>Manage reusable files for forms, landing pages and customer communication.</p></div>
        <button class="btn btn-primary" type="button" data-action="new-asset"><span class="fas fa-cloud-upload-alt" aria-hidden="true"></span> Upload asset</button>
    </header>
    <div data-asset-state="loading" class="nexa-asset-state"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span> Loading assets</div>
    <div data-asset-state="error" class="nexa-asset-state is-error" hidden><strong>Assets could not be loaded.</strong><button class="btn btn-default" type="button" data-action="reload">Try again</button></div>
    <div data-asset-state="ready" hidden>
        <div class="nexa-asset-toolbar">
            <label class="nexa-asset-search"><span class="fas fa-search" aria-hidden="true"></span><span class="sr-only">Search assets</span><input class="form-control" type="search" placeholder="Search assets" data-asset-search></label>
            <label><span class="sr-only">File type</span><select class="form-control" data-asset-type><option value="all">All file types</option><option value="image">Images</option><option value="document">Documents</option><option value="spreadsheet">Spreadsheets</option><option value="archive">Archives</option></select></label>
            <label><span class="sr-only">Asset status</span><select class="form-control" data-asset-status><option value="active">Active</option><option value="archived">Archived</option><option value="all">All statuses</option></select></label>
            <span class="nexa-asset-total" data-asset-total>0 assets</span>
        </div>
        <div class="nexa-asset-table-wrap" data-asset-scroll role="region" aria-label="Content assets" tabindex="0">
            <table class="table" data-workspace-table="assets"><thead><tr>
                <th data-column="name"><button type="button" data-table-sort="name">Asset</button></th>
                <th data-column="type"><button type="button" data-table-sort="type">Type</button></th>
                <th data-column="folder"><span>Folder</span></th>
                <th data-column="access"><span>Access</span></th>
                <th data-column="version"><span>Version</span></th>
                <th data-column="downloads"><button type="button" data-table-sort="downloads">Downloads</button></th>
                <th data-column="modifiedAt"><button type="button" data-table-sort="modifiedAt">Updated</button></th>
                <th data-column="actions"><span class="sr-only">Actions</span></th>
            </tr></thead><tbody data-asset-list></tbody></table>
        </div>
        <div class="nexa-asset-loaded" data-asset-loaded></div>
    </div>
</section>

<div class="nexa-asset-overlay" data-asset-dialog hidden aria-hidden="true" style="display:none">
    <div role="dialog" aria-modal="true" aria-labelledby="nexa-asset-dialog-title">
        <header><div><span class="nexa-asset-eyebrow">Asset library</span><h2 id="nexa-asset-dialog-title" data-asset-dialog-title>Upload asset</h2></div><button class="btn btn-icon" type="button" data-action="close-asset" title="Close"><span class="fas fa-times"></span></button></header>
        <form data-asset-form>
            <div class="nexa-asset-form-grid">
                <label class="is-wide" data-asset-file-field><span>File <strong>*</strong></span><input class="form-control" type="file" name="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.rtf,.zip,.png,.jpg,.jpeg,.gif,.webp"><small>Images up to 8 MB; other supported files up to 25 MB.</small></label>
                <label><span>Asset name <strong>*</strong></span><input class="form-control" name="displayName" maxlength="255" required></label>
                <label><span>Folder</span><select class="form-control" name="folderId" data-asset-folders><option value="">No folder</option></select></label>
                <label><span>Content language</span><input class="form-control" name="locale" value="en" maxlength="16"></label>
                <label><span>Access</span><select class="form-control" name="accessScope"><option value="internal">Internal</option><option value="public">Public content</option></select></label>
                <label class="is-wide"><span>Alternative text</span><input class="form-control" name="altText" maxlength="500"><small>Describe meaningful images for accessibility. Leave empty for decorative files.</small></label>
                <label class="is-wide"><span>Tags</span><input class="form-control" name="tags" placeholder="brand, product, brochure"><small>Separate tags with commas.</small></label>
                <label class="is-wide"><span>Description</span><textarea class="form-control" name="description" maxlength="1000" rows="3"></textarea></label>
                <label class="is-wide" data-asset-replacement hidden><span>Replace file</span><input class="form-control" type="file" name="replacement" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.rtf,.zip,.png,.jpg,.jpeg,.gif,.webp"><small>A replacement creates a new version and preserves the earlier version history.</small></label>
            </div>
            <div class="nexa-asset-form-error" data-asset-form-error hidden></div>
            <footer><button class="btn btn-primary" type="submit"><span class="fas fa-save"></span> Save asset</button><button class="btn btn-default" type="button" data-action="close-asset">Cancel</button></footer>
        </form>
    </div>
</div>

<div class="nexa-asset-overlay" data-asset-confirm-dialog hidden aria-hidden="true" style="display:none">
    <div class="nexa-asset-confirm" role="dialog" aria-modal="true" aria-labelledby="nexa-asset-confirm-title">
        <header><div><span class="nexa-asset-eyebrow">Asset library</span><h2 id="nexa-asset-confirm-title" data-asset-confirm-title>Archive asset?</h2></div><button class="btn btn-icon" type="button" data-action="close-asset-confirm" title="Close"><span class="fas fa-times"></span></button></header>
        <div class="nexa-asset-confirm-body"><p data-asset-confirm-copy></p><p class="nexa-asset-confirm-note"><span class="fas fa-info-circle" aria-hidden="true"></span> Archived assets stay in version history and can be restored later.</p></div>
        <footer><button class="btn btn-danger" type="button" data-action="confirm-asset-status">Archive asset</button><button class="btn btn-default" type="button" data-action="close-asset-confirm">Cancel</button></footer>
    </div>
</div>
