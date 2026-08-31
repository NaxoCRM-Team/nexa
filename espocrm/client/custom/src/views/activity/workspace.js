define('custom:views/activity/workspace', ['view'], Dep => class extends Dep {
    template = 'custom:activity/workspace';
    events = {
        'change [data-activity-filter]': 'changeFilter',
        'change [data-owner-filter]': 'changeFilter',
        'click [data-action="refreshActivity"]': 'loadData',
        'submit [data-project-form]': 'saveProject',
        'click [data-action="editProject"]': 'editProject',
        'click [data-action="archiveProject"]': 'archiveProject',
        'click [data-action="addProjectUpdate"]': 'addProjectUpdate',
        'click [data-action="viewProject"]': 'viewProject',
        'click [data-action="linkProjectRecord"]': 'linkProjectRecord',
        'click [data-action="unlinkProjectRecord"]': 'unlinkProjectRecord',
        'click [data-action="cancelProjectEdit"]': 'cancelProjectEdit',
    };

    setup() {
        this.section = this.options.section || 'agenda';
        this.filters = {type: '', ownerId: ''};
    }

    data() {
        return {
            isAgenda: this.section === 'agenda',
            isProjects: this.section === 'projects',
            hasActivityFilters: this.section === 'agenda',
        };
    }

    afterRender() {
        this.element?.classList.add('nexa-activity-workspace-page');
        this.loadData();
    }

    async loadData() {
        this.setState('loading');
        try {
            const requests = [Espo.Ajax.getRequest('Nexa/activity/workspace', {...this.filters})];
            if (this.section === 'projects') requests.push(Espo.Ajax.getRequest('Nexa/projects'));
            const [workspace, projects] = await Promise.all(requests);
            this.workspaceData = workspace;
            this.projectData = projects?.list || [];
            this.renderWorkspace();
            this.setState('ready');
        } catch (error) {
            this.setState(error?.status === 403 ? 'denied' : 'error');
        }
    }

    renderWorkspace() {
        this.renderMetrics();
        this.renderOwners();
        this.applyPermissions();
        if (this.section === 'agenda') this.renderAgenda();
        if (this.section === 'projects') this.renderProjects();
    }

    renderMetrics() {
        const metrics = this.workspaceData?.metrics || {};
        const items = [
            ['Activities in range', metrics.total, 'fas fa-list-check'],
            ['Today', metrics.today, 'fas fa-sun'],
            ['Overdue tasks', metrics.overdueTasks, 'fas fa-clock'],
            ['Next 7 days', metrics.upcoming, 'fas fa-calendar-day'],
        ];
        this.element.querySelector('[data-activity-metrics]').innerHTML = items.map(item =>
            `<article><span class="${item[2]}"></span><div><small>${item[0]}</small><strong>${Number(item[1] || 0).toLocaleString()}</strong></div></article>`
        ).join('');
    }

    renderOwners() {
        const select = this.element.querySelector('[data-owner-filter]');
        if (!select) return;
        const options = this.workspaceData?.permissions?.readAll
            ? '<option value="">All owners</option>'
            : '';
        select.innerHTML = options + (this.workspaceData?.owners || []).map(owner =>
            `<option value="${this.escape(owner.id)}" ${this.filters.ownerId === owner.id ? 'selected' : ''}>${this.escape(owner.name)}</option>`
        ).join('');
        if (!this.workspaceData?.permissions?.readAll && !this.filters.ownerId) {
            this.filters.ownerId = select.value;
        }
    }

    applyPermissions() {
        const permissions = this.workspaceData?.permissions || {};
        this.element.querySelectorAll('[data-create]').forEach(link => {
            const key = link.dataset.create.charAt(0).toLowerCase() + link.dataset.create.slice(1) + 'Create';
            link.hidden = !permissions[key];
        });
    }

    renderAgenda() {
        const host = this.element.querySelector('[data-agenda]');
        const groups = new Map();
        (this.workspaceData?.activities || []).forEach(item => {
            const key = String(item.dateStart).slice(0, 10);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(item);
        });
        host.innerHTML = [...groups.entries()].map(([date, items]) =>
            `<section><header><time datetime="${date}">${this.longDate(date)}</time><span>${items.length} ${items.length === 1 ? 'activity' : 'activities'}</span></header>
            <div>${items.map(item => this.activityCard(item)).join('')}</div></section>`
        ).join('') || this.empty('calendar-day', 'No activities match this range', 'Create a task, call or meeting to start planning the work.');
    }

    activityCard(item) {
        const icon = {Task: 'fa-check-square', Meeting: 'fa-calendar-check', Call: 'fa-phone'}[item.type] || 'fa-circle';
        const parent = item.parentType && item.parentId
            ? `<a href="#${this.escape(item.parentType)}/view/${this.escape(item.parentId)}">${this.escape(item.parentName || item.parentType)}</a>`
            : '<span>No related record</span>';
        return `<article class="nexa-activity-card nexa-activity-card--${item.type.toLowerCase()}">
            <span class="fas ${icon}" aria-hidden="true"></span><div><div><a href="#${item.type}/view/${this.escape(item.id)}">${this.escape(item.name)}</a><span class="nexa-status-pill">${this.escape(item.status)}</span></div>
            <small>${this.timeLabel(item.dateStart)} &middot; ${parent} &middot; ${this.escape(item.assignedUserName || 'Unassigned')}</small></div></article>`;
    }

    renderProjects() {
        const host = this.element.querySelector('[data-project-list]');
        const statuses = {Active: 'success', Planned: 'info', 'On Hold': 'warning', Completed: 'success', Canceled: 'danger'};
        host.innerHTML = (this.projectData || []).map(item => `<article data-project-id="${this.escape(item.id)}">
            <header><div><span class="label label-${statuses[item.status] || 'default'}">${this.escape(item.status)}</span><h2>${this.escape(item.name)}</h2></div>
            <div><button class="btn btn-icon" data-action="addProjectUpdate" data-id="${this.escape(item.id)}" title="Add project update"><span class="fas fa-comment-medical"></span></button>
            <button class="btn btn-icon" data-action="editProject" data-id="${this.escape(item.id)}" title="Edit project"><span class="fas fa-pen"></span></button>
            <button class="btn btn-icon text-danger" data-action="archiveProject" data-id="${this.escape(item.id)}" title="Archive project"><span class="fas fa-archive"></span></button></div></header>
            <p>${this.escape(item.description || 'No project description recorded.')}</p>
            <dl><div><dt>Owner</dt><dd>${this.escape(item.ownerName || 'Unassigned')}</dd></div><div><dt>Due</dt><dd>${this.escape(item.dateEnd || 'Not set')}</dd></div>
            <div><dt>Tasks</dt><dd>${item.taskCount}</dd></div><div><dt>Documents</dt><dd>${item.documentCount}</dd></div><div><dt>Updates</dt><dd>${item.updateCount}</dd></div></dl>
            <button class="btn btn-link nexa-project-view" data-action="viewProject" data-id="${this.escape(item.id)}"><span class="fas fa-folder-open"></span> View project work</button>
            <div class="nexa-project-detail" data-project-detail="${this.escape(item.id)}" hidden></div>
        </article>`).join('') || this.empty('folder-open', 'No projects yet', 'Create a project to coordinate work around native tasks and documents.');
        const select = this.element.querySelector('[data-project-owner]');
        select.innerHTML = '<option value="">Current user</option>' + (this.workspaceData?.owners || []).map(owner =>
            `<option value="${this.escape(owner.id)}">${this.escape(owner.name)}</option>`
        ).join('');
    }

    async saveProject(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const body = Object.fromEntries(new FormData(form).entries());
        const id = body.id; delete body.id;
        form.querySelectorAll('input,select,textarea,button').forEach(node => { node.disabled = true; });
        try {
            if (id) await Espo.Ajax.putRequest(`Nexa/projects/${encodeURIComponent(id)}`, body);
            else await Espo.Ajax.postRequest('Nexa/projects', body);
            Espo.Ui.success(id ? 'Project updated.' : 'Project created.');
            this.cancelProjectEdit();
            await this.loadData();
        } catch (error) {
            Espo.Ui.error(error?.message || 'Project could not be saved.');
        } finally {
            form.querySelectorAll('input,select,textarea,button').forEach(node => { node.disabled = false; });
        }
    }

    editProject(event) {
        const item = (this.projectData || []).find(project => project.id === event.currentTarget.dataset.id);
        const form = this.element.querySelector('[data-project-form]');
        if (!item || !form) return;
        ['id','name','status','priority','dateStart','dateEnd','ownerId','description'].forEach(name => {
            if (form.elements[name]) form.elements[name].value = item[name] || '';
        });
        form.querySelector('[data-project-form-title]').textContent = 'Edit project';
        form.elements.name.focus();
    }

    cancelProjectEdit() {
        const form = this.element.querySelector('[data-project-form]');
        if (!form) return;
        form.reset(); form.elements.id.value = '';
        form.querySelector('[data-project-form-title]').textContent = 'New project';
    }

    async archiveProject(event) {
        if (!confirm('Archive this project? Its native tasks and documents will remain available.')) return;
        try {
            await Espo.Ajax.deleteRequest(`Nexa/projects/${encodeURIComponent(event.currentTarget.dataset.id)}`);
            Espo.Ui.success('Project archived.'); await this.loadData();
        } catch (error) { Espo.Ui.error(error?.message || 'Project could not be archived.'); }
    }

    async addProjectUpdate(event) {
        const body = prompt('Share a project update');
        if (!body?.trim()) return;
        try {
            await Espo.Ajax.postRequest(`Nexa/projects/${encodeURIComponent(event.currentTarget.dataset.id)}/updates`, {body: body.trim()});
            Espo.Ui.success('Project update added.'); await this.loadData();
        } catch (error) { Espo.Ui.error(error?.message || 'Project update could not be added.'); }
    }

    async viewProject(event) {
        const id = event.currentTarget.dataset.id;
        const host = this.element.querySelector(`[data-project-detail="${CSS.escape(id)}"]`);
        if (!host) return;
        if (!host.hidden) { host.hidden = true; return; }
        host.hidden = false;
        host.innerHTML = '<p class="nexa-project-loading"><span class="fas fa-circle-notch fa-spin"></span> Loading project work...</p>';
        try {
            const project = await Espo.Ajax.getRequest(`Nexa/projects/${encodeURIComponent(id)}`);
            host.innerHTML = this.projectDetail(project);
        } catch (error) {
            host.innerHTML = '<p class="text-danger">Project work could not be loaded.</p>';
        }
    }

    projectDetail(project) {
        const records = (type, list) => `<section><header><h3>${type === 'Task' ? 'Tasks' : 'Documents'}</h3>
            <button class="btn btn-link" data-action="linkProjectRecord" data-id="${this.escape(project.id)}" data-type="${type}"><span class="fas fa-plus"></span> Add</button></header>
            <div>${(list || []).map(item => `<article><a href="#${type}/view/${this.escape(item.id)}">${this.escape(item.name)}</a>
            <span>${this.escape(item.status || '')}</span><button class="btn btn-icon" data-action="unlinkProjectRecord" data-id="${this.escape(project.id)}" data-type="${type}" data-record-id="${this.escape(item.id)}" title="Remove from project"><span class="fas fa-times"></span></button></article>`).join('') || '<p>No records connected.</p>'}</div></section>`;
        const updates = `<section class="nexa-project-updates"><header><h3>Updates</h3></header><div>${(project.updates || []).map(item =>
            `<article><p>${this.escape(item.body)}</p><small>${this.escape(item.createdByName)} &middot; ${this.shortDate(item.createdAt)}</small></article>`
        ).join('') || '<p>No project updates yet.</p>'}</div></section>`;
        return `<div class="nexa-project-links">${records('Task', project.tasks)}${records('Document', project.documents)}</div>${updates}`;
    }

    linkProjectRecord(event) {
        const {id, type} = event.currentTarget.dataset;
        this.createView(`project${type}Selector`, 'views/modals/select-records', {
            scope: type,
            multiple: false,
            createButton: true,
            headerText: `Add ${type.toLowerCase()} to project`,
        }, view => {
            view.render();
            this.listenToOnce(view, 'select', async model => {
                if (!model?.id) return;
                try {
                    await Espo.Ajax.postRequest(`Nexa/projects/${encodeURIComponent(id)}/links`, {type, recordId: model.id});
                    Espo.Ui.success(`${type} added to project.`);
                    await this.refreshProjectDetail(id);
                } catch (error) { Espo.Ui.error(error?.message || `${type} could not be added.`); }
            });
        });
    }

    async unlinkProjectRecord(event) {
        const {id, type, recordId} = event.currentTarget.dataset;
        try {
            await Espo.Ajax.deleteRequest(`Nexa/projects/${encodeURIComponent(id)}/links/${encodeURIComponent(type)}/${encodeURIComponent(recordId)}`);
            Espo.Ui.success(`${type} removed from project.`);
            await this.refreshProjectDetail(id);
        } catch (error) { Espo.Ui.error(error?.message || `${type} could not be removed.`); }
    }

    async refreshProjectDetail(id) {
        const host = this.element.querySelector(`[data-project-detail="${CSS.escape(id)}"]`);
        if (!host) return;
        const project = await Espo.Ajax.getRequest(`Nexa/projects/${encodeURIComponent(id)}`);
        host.hidden = false;
        host.innerHTML = this.projectDetail(project);
    }

    changeFilter(event) { this.filters[event.currentTarget.name] = event.currentTarget.value; this.loadData(); }
    setState(state) { this.element?.querySelectorAll('[data-activity-state]').forEach(node => { node.hidden = node.dataset.activityState !== state; }); }
    empty(icon, title, copy) { return `<div class="nexa-activity-empty"><span class="far fa-${icon}"></span><h2>${title}</h2><p>${copy}</p></div>`; }
    isoDate(value) { return [value.getFullYear(), String(value.getMonth() + 1).padStart(2, '0'), String(value.getDate()).padStart(2, '0')].join('-'); }
    longDate(value) { return new Intl.DateTimeFormat(undefined, {weekday: 'long', day: 'numeric', month: 'long'}).format(new Date(value + 'T12:00:00')); }
    shortDate(value) { return value ? new Intl.DateTimeFormat(undefined, {day: 'numeric', month: 'short', year: 'numeric'}).format(new Date(String(value).replace(' ', 'T'))) : 'not recorded'; }
    timeLabel(value) { return new Intl.DateTimeFormat(undefined, {hour: 'numeric', minute: '2-digit'}).format(new Date(String(value).replace(' ', 'T') + 'Z')); }
    escape(value) { const node = document.createElement('span'); node.textContent = String(value ?? ''); return node.innerHTML; }
});
