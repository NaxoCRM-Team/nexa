<div class="nexa-export-modal-intro">
    <span class="fas fa-file-export" aria-hidden="true"></span>
    <div>
        <h4>Export contacts</h4>
        <p>{{source}} &middot; {{count}} records</p>
    </div>
</div>
<div class="nexa-export-modal-note" role="note">
    Choose the permitted fields and file format. The completed file will download automatically and remain available in Export Audit.
</div>
<div class="form-group nexa-export-name-field">
    <label for="nexa-contact-export-name">Export name</label>
    <input id="nexa-contact-export-name" class="form-control" data-name="exportName" maxlength="100" autocomplete="off" value="{{defaultExportName}}" aria-describedby="nexa-export-name-help">
    <p id="nexa-export-name-help" class="help-block">The selected file extension is added automatically.</p>
    <p class="help-block text-danger hidden" data-name="exportNameError">Enter a name using letters, numbers, spaces, hyphens or underscores.</p>
</div>
<div class="record no-side-margin">{{{record}}}</div>
