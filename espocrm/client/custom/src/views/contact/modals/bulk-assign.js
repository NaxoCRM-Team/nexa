define('custom:views/contact/modals/bulk-assign', ['views/modal'], Dep => class extends Dep {
    template = 'custom:contact/modals/bulk-assign';
    cssName = 'nexa-contact-bulk-assign-modal';
    className = 'dialog nexa-contact-bulk-assign-dialog';
    noFullHeight = true;

    setup() {
        this.count = Number(this.options.count) || 0;
        this.userList = [];
        this.filteredUserList = [];
        this.selectedUser = null;
        this.headerText = `Bulk assign ${this.count} ${this.count === 1 ? 'record' : 'records'}`;
        this.addButton({name: 'update', label: 'Update', style: 'primary'});
        this.addButton({name: 'cancel', label: 'Cancel'});
    }

    data() {
        return {count: this.count};
    }

    afterRender() {
        super.afterRender();
        this.bindOwnerPicker();
        this.loadUsers();
    }

    bindOwnerPicker() {
        const trigger = this.element.querySelector('[data-action="toggleOwners"]');
        const search = this.element.querySelector('[data-name="ownerSearch"]');

        trigger?.addEventListener('click', () => this.toggleOwnerList());
        search?.addEventListener('input', event => this.filterUsers(event.currentTarget.value));
        search?.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            this.closeOwnerList();
            trigger?.focus();
        });

        this.ownerOutsideHandler = event => {
            if (!this.element.querySelector('.nexa-owner-picker')?.contains(event.target)) this.closeOwnerList();
        };
        document.addEventListener('click', this.ownerOutsideHandler);
        this.once('remove', () => document.removeEventListener('click', this.ownerOutsideHandler));
    }

    async loadUsers() {
        const list = this.element.querySelector('[data-name="ownerOptions"]');

        try {
            const result = await Espo.Ajax.getRequest('Nexa/contact/assignees');
            this.userList = result.list || [];
            this.filteredUserList = [...this.userList];
            this.renderUsers();
        } catch (error) {
            if (list) list.innerHTML = '<li class="nexa-owner-option-state text-danger">Owners could not be loaded.</li>';
            this.disableButton('update');
        }
    }

    toggleOwnerList() {
        const panel = this.element.querySelector('[data-name="ownerPanel"]');
        const trigger = this.element.querySelector('[data-action="toggleOwners"]');
        if (!panel || !trigger) return;

        const shouldOpen = panel.hidden;
        panel.hidden = !shouldOpen;
        trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');

        if (shouldOpen) {
            const search = panel.querySelector('[data-name="ownerSearch"]');
            search.value = '';
            this.filterUsers('');
            window.setTimeout(() => search.focus(), 0);
        }
    }

    closeOwnerList() {
        const panel = this.element.querySelector('[data-name="ownerPanel"]');
        const trigger = this.element.querySelector('[data-action="toggleOwners"]');
        if (panel) panel.hidden = true;
        trigger?.setAttribute('aria-expanded', 'false');
    }

    filterUsers(value) {
        const term = String(value || '').trim().toLowerCase();
        this.filteredUserList = this.userList.filter(user =>
            `${user.name || ''} ${user.emailAddress || ''}`.toLowerCase().includes(term)
        );
        this.renderUsers(term);
    }

    renderUsers(term = '') {
        const list = this.element.querySelector('[data-name="ownerOptions"]');
        if (!list) return;

        list.replaceChildren();

        if (!term) list.appendChild(this.ownerOption(null, 'No owner', 'Clear the current owner'));

        this.filteredUserList.forEach(user => {
            list.appendChild(this.ownerOption(user, user.name, user.emailAddress || 'Tenant user'));
        });

        if (!list.children.length) {
            list.innerHTML = '<li class="nexa-owner-option-state">No matching owners.</li>';
        }
    }

    ownerOption(user, name, detail) {
        const item = document.createElement('li');
        const button = document.createElement('button');
        const initials = document.createElement('span');
        const copy = document.createElement('span');
        const primary = document.createElement('strong');
        const secondary = document.createElement('small');

        button.type = 'button';
        button.className = 'nexa-owner-option';
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', (user?.id || '') === (this.selectedUser?.id || '') ? 'true' : 'false');
        initials.className = 'nexa-owner-option-avatar';
        initials.textContent = user ? this.initials(name) : '-';
        copy.className = 'nexa-owner-option-copy';
        primary.textContent = name;
        secondary.textContent = detail;
        copy.append(primary, secondary);
        button.append(initials, copy);
        button.addEventListener('click', () => this.selectUser(user));
        item.appendChild(button);

        return item;
    }

    selectUser(user) {
        this.selectedUser = user;
        const label = this.element.querySelector('[data-name="selectedOwner"]');
        if (label) label.textContent = user?.name || 'No owner';
        this.closeOwnerList();
    }

    initials(name) {
        return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2)
            .map(part => part.charAt(0).toUpperCase()).join('') || '?';
    }

    actionUpdate() {
        this.trigger('confirm', this.selectedUser);
        this.close();
    }
});
