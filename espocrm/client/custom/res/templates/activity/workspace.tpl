<section class="nexa-activity-workspace" aria-labelledby="nexa-activity-heading">
    <header class="nexa-activity-header">
        <div>
            <p>Sales activity</p>
            <h1 id="nexa-activity-heading">Activity Workspace</h1>
            <span>Plan tasks, calls, meetings and customer follow-up in one place.</span>
        </div>
        <div class="nexa-activity-create" aria-label="Create activity">
            <a class="btn btn-default" data-create="Task" href="#Task/create"><span class="fas fa-check-square"></span> Task</a>
            <a class="btn btn-default" data-create="Meeting" href="#Meeting/create"><span class="fas fa-calendar-check"></span> Meeting</a>
            <a class="btn btn-default" data-create="Call" href="#Call/create"><span class="fas fa-phone"></span> Call</a>
        </div>
    </header>

    <nav class="nexa-activity-tabs" aria-label="Activity workspace">
        <a href="#NexaActivity/agenda" {{#if isAgenda}}aria-current="page"{{/if}}>Agenda</a>
        <a href="#NexaActivity/projects" {{#if isProjects}}aria-current="page"{{/if}}>Projects</a>
        <span class="nexa-activity-module-links">
            <a href="#Calendar"><span class="far fa-calendar-alt" aria-hidden="true"></span> Calendar</a>
            <a href="#Document"><span class="far fa-file-alt" aria-hidden="true"></span> Documents</a>
        </span>
    </nav>

    <div class="nexa-activity-state" data-activity-state="loading" role="status">
        <span class="fas fa-circle-notch fa-spin"></span><p>Loading your activity workspace...</p>
    </div>
    <div class="nexa-activity-state" data-activity-state="error" hidden>
        <span class="fas fa-exclamation-circle"></span><h2>Activity data could not be loaded</h2>
        <button class="btn btn-default" data-action="refreshActivity">Try again</button>
    </div>
    <div class="nexa-activity-state" data-activity-state="denied" hidden>
        <span class="fas fa-lock"></span><h2>This workspace is not available for your role</h2>
    </div>

    <div data-activity-state="ready" hidden>
        <section class="nexa-activity-metrics" data-activity-metrics aria-label="Activity summary"></section>
        {{#if hasActivityFilters}}
        <section class="nexa-activity-toolbar" aria-label="Activity filters">
            <label>Activity type<select class="form-control" name="type" data-activity-filter>
                <option value="">All activities</option><option value="Task">Tasks</option>
                <option value="Meeting">Meetings</option><option value="Call">Calls</option>
            </select></label>
            <label>Owner<select class="form-control" name="ownerId" data-owner-filter></select></label>
            <button class="btn btn-default btn-icon" data-action="refreshActivity" title="Refresh activities"><span class="fas fa-sync-alt"></span></button>
        </section>
        {{/if}}
        {{#if isAgenda}}<div class="nexa-agenda" data-agenda></div>{{/if}}
        {{#if isProjects}}
        <section class="nexa-project-workspace">
            <div class="nexa-project-list" data-project-list></div>
            <form class="nexa-project-form" data-project-form>
                <input type="hidden" name="id">
                <div><p>Team delivery</p><h2 data-project-form-title>New project</h2></div>
                <label>Project name<input class="form-control" name="name" maxlength="200" required></label>
                <div class="nexa-project-form-row">
                    <label>Status<select class="form-control" name="status"><option>Planned</option><option>Active</option><option>On Hold</option><option>Completed</option><option>Canceled</option></select></label>
                    <label>Priority<select class="form-control" name="priority"><option>Low</option><option selected>Normal</option><option>High</option><option>Urgent</option></select></label>
                </div>
                <div class="nexa-project-form-row">
                    <label>Start date<input class="form-control" type="date" name="dateStart"></label>
                    <label>End date<input class="form-control" type="date" name="dateEnd"></label>
                </div>
                <label>Owner<select class="form-control" name="ownerId" data-project-owner></select></label>
                <label>Description<textarea class="form-control" name="description" rows="4" maxlength="5000"></textarea></label>
                <footer><button class="btn btn-primary" type="submit">Save project</button><button class="btn btn-default" type="button" data-action="cancelProjectEdit">Cancel</button></footer>
            </form>
        </section>
        {{/if}}
    </div>
</section>
