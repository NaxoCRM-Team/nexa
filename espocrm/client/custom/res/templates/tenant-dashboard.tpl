<div class="nexa-dashboard" aria-labelledby="nexa-dashboard-title">
    <header class="nexa-dashboard-hero">
        <div>
            <div class="nexa-workspace-context"><span class="nexa-workspace-dot" aria-hidden="true"></span>{{tenant.displayName}} workspace</div>
            <h1 id="nexa-dashboard-title">Good to see you, {{firstName}}</h1>
            <p>Here is what needs attention across your customers and pipeline.</p>
        </div>
        <div class="nexa-dashboard-controls">
            <label for="nexa-dashboard-range">Reporting period</label>
            <div>
                <select id="nexa-dashboard-range" class="form-control" data-dashboard-range>
                    <option value="7d">Last 7 days</option><option value="30d" selected>Last 30 days</option>
                    <option value="90d">Last 90 days</option><option value="all">All time</option>
                </select>
                <button class="btn btn-default btn-icon" type="button" data-action="refreshDashboard" title="Refresh dashboard" aria-label="Refresh dashboard"><span class="fas fa-sync-alt" aria-hidden="true"></span></button>
            </div>
            <small data-dashboard-updated aria-live="polite"></small>
        </div>
    </header>

    <section class="nexa-dashboard-summary" data-dashboard-summary data-state="loading" aria-live="polite" aria-busy="true">
        <div class="nexa-dashboard-state" data-dashboard-state="loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><p>Loading your workspace overview...</p></div>
        <div class="nexa-dashboard-state" data-dashboard-state="empty" hidden><span class="far fa-folder-open" aria-hidden="true"></span><h2>Your workspace is ready</h2><p>Create your first customer or opportunity to bring this dashboard to life.</p><a class="btn btn-primary" href="#Account/create">Create an account</a></div>
        <div class="nexa-dashboard-state" data-dashboard-state="error" hidden><span class="fas fa-exclamation-circle" aria-hidden="true"></span><h2>We could not load the overview</h2><p>Your saved dashboard is still available below.</p><button class="btn btn-default" type="button" data-action="retryDashboard">Try again</button></div>
        <div class="nexa-dashboard-state" data-dashboard-state="denied" hidden><span class="fas fa-lock" aria-hidden="true"></span><h2>Overview unavailable</h2><p>Your role does not provide access to workspace-wide dashboard metrics.</p></div>

        <div data-dashboard-state="ready" hidden>
            <div class="nexa-metric-grid" aria-label="Workspace metrics">
                <article class="nexa-metric"><span class="fas fa-building" aria-hidden="true"></span><div><p>New accounts</p><strong data-metric="accounts">0</strong></div></article>
                <article class="nexa-metric"><span class="fas fa-address-book" aria-hidden="true"></span><div><p>New contacts</p><strong data-metric="contacts">0</strong></div></article>
                <article class="nexa-metric"><span class="fas fa-user-plus" aria-hidden="true"></span><div><p>New leads</p><strong data-metric="leads">0</strong></div></article>
                <article class="nexa-metric"><span class="fas fa-handshake" aria-hidden="true"></span><div><p>Open opportunities</p><strong data-metric="openOpportunities">0</strong></div></article>
                <article class="nexa-metric"><span class="fas fa-coins" aria-hidden="true"></span><div><p>Pipeline value</p><strong data-metric="pipelineValue">0</strong></div></article>
                <article class="nexa-metric"><span class="fas fa-check-square" aria-hidden="true"></span><div><p>Open tasks</p><strong data-metric="openTasks">0</strong></div></article>
            </div>
            <div class="nexa-dashboard-insights">
                <section aria-labelledby="nexa-pipeline-title"><div class="nexa-section-heading"><div><p>Sales</p><h2 id="nexa-pipeline-title">Pipeline by stage</h2></div><a href="#Opportunity">View opportunities</a></div><ul class="nexa-pipeline-list" data-pipeline-list></ul></section>
                <section aria-labelledby="nexa-activity-title"><div class="nexa-section-heading"><div><p>Schedule</p><h2 id="nexa-activity-title">Coming up</h2></div><a href="#Calendar">Open calendar</a></div><ul class="nexa-activity-list" data-activity-list></ul></section>
            </div>
        </div>
    </section>

    <section class="nexa-saved-dashboard" aria-labelledby="nexa-saved-dashboard-title">
        <div class="page-header dashboard-header nexa-saved-dashboard-header">
            <div><p>Custom workspace</p><h2 id="nexa-saved-dashboard-title">Your dashboard</h2></div>
            <div class="nexa-dashboard-actions">
                {{#ifNotEqual dashboardLayout.length 1}}<div class="btn-group dashboard-tabs">{{#each dashboardLayout}}<button class="btn btn-text{{#ifEqual @index ../currentTab}} active{{/ifEqual}}" data-action="selectTab" data-tab="{{@index}}">{{name}}</button>{{/each}}</div>{{/ifNotEqual}}
                {{#unless layoutReadOnly}}<div class="btn-group dashboard-buttons"><button class="btn btn-default btn-icon dropdown-toggle" data-toggle="dropdown" aria-label="Dashboard settings" title="Dashboard settings"><span class="fas fa-cog" aria-hidden="true"></span></button><ul class="dropdown-menu pull-right dropdown-menu-with-icons"><li><a role="button" tabindex="0" data-action="editTabs"><span class="fas fa-pencil-alt fa-sm"></span><span class="item-text">{{translate 'Edit Dashboard'}}</span></a></li>{{#if hasAdd}}<li><a role="button" tabindex="0" data-action="addDashlet"><span class="fas fa-plus"></span><span class="item-text">{{translate 'Add Dashlet'}}</span></a></li>{{/if}}</ul></div>{{/unless}}
            </div>
        </div>
        <div class="dashlets grid-stack grid-stack-12">{{{dashlets}}}</div>
    </section>
</div>
