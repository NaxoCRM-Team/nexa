<main class="nexa-object-admin">
    <header class="nexa-object-admin-header">
        <div>
            <p class="nexa-admin-eyebrow">Administration / Data management</p>
            <h1>Objects &amp; properties</h1>
            <p class="nexa-admin-summary">Choose a record type, then manage the information it stores and how records connect.</p>
        </div>
        <div class="nexa-admin-header-actions">
            <button type="button" class="btn btn-default" data-action="refresh" title="Refresh"><span class="fas fa-sync-alt" aria-hidden="true"></span></button>
            <button type="button" class="btn btn-primary" data-action="open-object-dialog"><span class="fas fa-plus" aria-hidden="true"></span><span>Create custom object</span></button>
        </div>
    </header>

    <section class="nexa-object-overview" data-screen="overview">
        <div class="nexa-object-intro"><div><span class="fas fa-database" aria-hidden="true"></span><div><strong>Start with the information you want to manage</strong><p>Contacts and Accounts are ready to customize. Create a custom object only when the information does not fit an existing record type.</p></div></div></div>
        <div class="nexa-object-toolbar">
            <div><h2>Choose an object</h2><p>Properties, record layout and associations are managed inside each object.</p></div>
            <label class="nexa-object-search"><span class="fas fa-search" aria-hidden="true"></span><span class="sr-only">Search objects</span><input type="search" data-object-search placeholder="Search objects"></label>
        </div>
        <div class="nexa-object-grid" data-object-list></div>
        <div class="nexa-object-guide" aria-label="Recommended setup order">
            <h2>How customization works</h2>
            <ol>
                <li><span>1</span><div><strong>Choose an object</strong><p>Select Contacts, Accounts or a custom object.</p></div></li>
                <li><span>2</span><div><strong>Add the information you need</strong><p>Create properties such as Membership number or Customer category.</p></div></li>
                <li><span>3</span><div><strong>Arrange and connect records</strong><p>Choose where properties appear and create associations when needed.</p></div></li>
            </ol>
        </div>
    </section>

    <section class="nexa-object-workspace" data-screen="workspace" hidden>
        <button type="button" class="nexa-object-back" data-action="back-to-objects"><span class="fas fa-arrow-left" aria-hidden="true"></span>All objects</button>
        <div class="nexa-object-heading"><div class="nexa-object-heading-icon" data-object-icon aria-hidden="true"></div><div><div class="nexa-object-heading-label"><h2 data-object-title></h2><span data-object-kind></span></div><p data-object-description></p></div></div>
        <nav class="nexa-object-tabs" role="tablist" aria-label="Object settings">
            <button type="button" id="properties-tab" data-object-tab="properties" role="tab" aria-controls="properties-panel" aria-selected="true" class="is-active">Properties</button>
            <button type="button" id="layout-tab" data-object-tab="layout" role="tab" aria-controls="layout-panel" aria-selected="false">Record layout</button>
            <button type="button" id="associations-tab" data-object-tab="associations" role="tab" aria-controls="associations-panel" aria-selected="false">Associations</button>
            <button type="button" id="records-tab" data-object-tab="records" role="tab" aria-controls="records-panel" aria-selected="false" data-custom-only>Records</button>
        </nav>

        <section id="properties-panel" class="nexa-object-panel" data-object-panel="properties" role="tabpanel" aria-labelledby="properties-tab">
            <header class="nexa-panel-header"><div><h3>Properties</h3><p>Properties are the fields that store information on each record.</p></div><button type="button" class="btn btn-primary" data-action="open-property-dialog"><span class="fas fa-plus" aria-hidden="true"></span>Add property</button></header>
            <div class="nexa-list-toolbar"><label><span class="fas fa-search" aria-hidden="true"></span><span class="sr-only">Search properties</span><input type="search" data-property-search placeholder="Search properties"></label><span data-property-count></span></div>
            <div class="nexa-property-list" data-property-list></div>
        </section>

        <section id="layout-panel" class="nexa-object-panel" data-object-panel="layout" role="tabpanel" aria-labelledby="layout-tab" hidden>
            <header class="nexa-panel-header"><div><h3>Record layout</h3><p>Arrange the additional properties shown on each Nexa screen.</p></div><button type="button" class="btn btn-primary" data-action="save-layout"><span class="fas fa-save" aria-hidden="true"></span>Publish layout</button></header>
            <div class="nexa-layout-controls"><label>Screen<select data-layout-context><option value="create">Create record</option><option value="edit">Edit record</option><option value="detail">Record details</option></select></label><p>Choose which properties appear, then use the arrow controls to set their display order.</p></div>
            <div class="nexa-layout-workspace"><div><h4>Displayed properties</h4><div class="nexa-layout-builder" data-layout-builder></div></div><aside class="nexa-layout-preview"><span>Preview</span><div class="nexa-layout-preview-shell"><strong data-preview-title></strong><div data-layout-preview></div></div></aside></div>
        </section>

        <section id="associations-panel" class="nexa-object-panel" data-object-panel="associations" role="tabpanel" aria-labelledby="associations-tab" hidden>
            <header class="nexa-panel-header"><div><h3>Associations</h3><p>Associations describe how this object connects to other records.</p></div><button type="button" class="btn btn-primary" data-action="open-association-dialog"><span class="fas fa-plus" aria-hidden="true"></span>Create association</button></header>
            <div class="nexa-association-list" data-association-list></div>
        </section>

        <section id="records-panel" class="nexa-object-panel" data-object-panel="records" role="tabpanel" aria-labelledby="records-tab" hidden>
            <header class="nexa-panel-header"><div><h3>Records</h3><p>Create sample records and confirm that your object structure works as expected.</p></div><button type="button" class="btn btn-primary" data-action="toggle-record-form"><span class="fas fa-plus" aria-hidden="true"></span>Create record</button></header>
            <div data-custom-records></div>
        </section>
    </section>

    <div class="nexa-admin-dialog" data-dialog="property" role="dialog" aria-modal="true" aria-labelledby="property-dialog-title" hidden>
        <div class="nexa-admin-dialog-backdrop" data-action="close-dialog"></div>
        <section class="nexa-admin-dialog-panel"><header><div><p>New property</p><h2 id="property-dialog-title">Add information to <span data-property-object-name></span></h2></div><button type="button" data-action="close-dialog" aria-label="Close"><span class="fas fa-times"></span></button></header>
            <form data-form="field"><div class="nexa-dialog-body">
                <label>Property name<input name="label" maxlength="120" placeholder="Membership number" autocomplete="off" required data-key-source></label>
                <label>Information type<select name="dataType" required></select></label>
                <label class="nexa-property-options" hidden>Choices <span>Enter one choice per line.</span><textarea name="options" rows="5" placeholder="Standard&#10;Premium&#10;Enterprise"></textarea></label>
                <label>Description <span>Optional</span><textarea name="description" rows="2" maxlength="500" placeholder="Help users understand what to enter."></textarea></label>
                <fieldset class="nexa-rule-options"><legend>Rules</legend><label><input type="checkbox" name="isRequired">Require a value</label><label><input type="checkbox" name="isUnique">Do not allow duplicate values</label><label><input type="checkbox" name="isSearchable">Include in search</label></fieldset>
                <fieldset class="nexa-placement-options"><legend>Show this property on</legend><label><input type="checkbox" name="showOn" value="create" checked>Create form</label><label><input type="checkbox" name="showOn" value="edit" checked>Edit form</label><label><input type="checkbox" name="showOn" value="detail" checked>Record details</label></fieldset>
                <details class="nexa-advanced-settings"><summary>Advanced settings</summary><label>Internal name <span>Used by APIs and integrations. It cannot be changed later.</span><input name="fieldKey" pattern="[a-z][a-z0-9_]{1,63}" required data-generated-key></label></details>
            </div><footer><button type="button" class="btn btn-default" data-action="close-dialog">Cancel</button><button type="submit" class="btn btn-primary">Create property</button></footer></form>
        </section>
    </div>

    <div class="nexa-admin-dialog" data-dialog="object" role="dialog" aria-modal="true" aria-labelledby="object-dialog-title" hidden>
        <div class="nexa-admin-dialog-backdrop" data-action="close-dialog"></div>
        <section class="nexa-admin-dialog-panel"><header><div><p>Custom object</p><h2 id="object-dialog-title">Create a new record type</h2></div><button type="button" data-action="close-dialog" aria-label="Close"><span class="fas fa-times"></span></button></header>
            <form data-form="entity"><div class="nexa-dialog-body"><div class="nexa-dialog-callout"><span class="fas fa-lightbulb"></span><p>Use a custom object when Contacts or Accounts cannot naturally store the information, such as Vehicles, Properties or Contracts.</p></div><label>What do you want to track?<input name="label" maxlength="120" placeholder="Vehicle" required data-key-source></label><label>Plural name<input name="pluralLabel" maxlength="120" placeholder="Vehicles" required></label><label>Description <span>Optional</span><textarea name="description" rows="3" maxlength="500" placeholder="Vehicles owned by customers"></textarea></label><details class="nexa-advanced-settings"><summary>Advanced settings</summary><label>Internal name <span>Used by APIs and integrations.</span><input name="entityKey" pattern="[a-z][a-z0-9_]{1,63}" required data-generated-key></label></details></div><footer><button type="button" class="btn btn-default" data-action="close-dialog">Cancel</button><button type="submit" class="btn btn-primary">Create object</button></footer></form>
        </section>
    </div>

    <div class="nexa-admin-dialog" data-dialog="association" role="dialog" aria-modal="true" aria-labelledby="association-dialog-title" hidden>
        <div class="nexa-admin-dialog-backdrop" data-action="close-dialog"></div>
        <section class="nexa-admin-dialog-panel"><header><div><p>New association</p><h2 id="association-dialog-title">Connect <span data-association-source-name></span> to another object</h2></div><button type="button" data-action="close-dialog" aria-label="Close"><span class="fas fa-times"></span></button></header>
            <form data-form="relationship"><div class="nexa-dialog-body"><input type="hidden" name="sourceEntityType"><label>Connect to<select name="targetEntityType" required></select></label><div class="nexa-association-sentence"><label>One <strong data-source-singular></strong> can be connected to<select name="sourceMultiplicity"><option value="many">many</option><option value="one">one</option></select><strong data-target-plural>records</strong>.</label><label>One <strong data-target-singular>record</strong> can be connected to<select name="targetMultiplicity"><option value="one">one</option><option value="many">many</option></select><strong data-source-plural></strong>.</label></div><label>Label shown on this object<input name="label" maxlength="120" required></label><label>Label shown on the related object<input name="inverseLabel" maxlength="120" required></label><details class="nexa-advanced-settings"><summary>Advanced settings</summary><label>Internal name<input name="relationshipKey" pattern="[a-z][a-z0-9_]{1,63}" required data-generated-key></label></details></div><footer><button type="button" class="btn btn-default" data-action="close-dialog">Cancel</button><button type="submit" class="btn btn-primary">Create association</button></footer></form>
        </section>
    </div>

    <div class="nexa-admin-dialog nexa-record-association-dialog" data-dialog="record-associations" role="dialog" aria-modal="true" aria-labelledby="record-association-dialog-title" hidden>
        <div class="nexa-admin-dialog-backdrop" data-action="close-dialog"></div>
        <section class="nexa-admin-dialog-panel">
            <header>
                <div><p>Record associations</p><h2 id="record-association-dialog-title">Connect <span data-association-record-name></span></h2></div>
                <button type="button" data-action="close-dialog" aria-label="Close"><span class="fas fa-times"></span></button>
            </header>
            <div class="nexa-dialog-body nexa-record-association-body" data-record-association-list></div>
            <footer><button type="button" class="btn btn-default" data-action="close-dialog">Done</button></footer>
        </section>
    </div>
</main>
