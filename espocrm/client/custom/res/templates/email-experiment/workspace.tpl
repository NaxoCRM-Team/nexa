<section class="nexa-experiments">
    <header class="ne-header"><div><a href="#NexaMarketingEmail" class="ne-back">Marketing Email</a><h1>Email experiments</h1></div><div class="ne-toolbar"><button class="btn btn-default" data-action="refresh" title="Refresh"><span class="fas fa-sync-alt" aria-hidden="true"></span></button><button class="btn btn-primary" data-action="new"><span class="fas fa-plus" aria-hidden="true"></span> New experiment</button></div></header>
    <div role="alert" data-error hidden></div>
    <div data-loading role="status">Loading experiments...</div>
    <form data-create hidden class="ne-form">
        <h2>New experiment</h2>
        <label>Name<input name="name" maxlength="160" required class="form-control"></label>
        <div class="ne-grid"><label>Variant A<select name="sourceA" required class="form-control"></select></label><label>Variant B<select name="sourceB" required class="form-control"></select></label></div>
        <div class="ne-grid ne-three"><label>Test audience (%)<input name="testPercent" type="number" min="2" max="100" value="20" required class="form-control"></label><label>Holdout (%)<input name="holdoutPercent" type="number" min="0" max="98" value="0" required class="form-control"></label><label>Test window (minutes)<input name="windowMinutes" type="number" min="1" max="10080" value="1440" required class="form-control"></label></div>
        <footer class="ne-toolbar"><button class="btn btn-primary" type="submit">Create experiment</button><button class="btn btn-default" type="button" data-action="close-create">Cancel</button></footer>
    </form>
    <div class="ne-toolbar ne-search"><input class="form-control" type="search" data-search placeholder="Search experiments" aria-label="Search experiments"><span data-total></span></div>
    <div class="ne-table-scroll"><table class="table" data-experiment-table><thead><tr><th data-column="name"><button data-sort="name">Name <span class="fas fa-sort" aria-hidden="true"></span></button></th><th data-column="status"><button data-sort="status">Status <span class="fas fa-sort" aria-hidden="true"></span></button></th><th data-column="audience">Test / holdout</th><th data-column="winner">Winner</th><th data-column="createdAt">Created</th></tr></thead><tbody data-rows></tbody></table></div>
    <section data-detail hidden></section>
</section>
