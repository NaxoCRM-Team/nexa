define('custom:views/contact/fields/lead-status-list', ['views/fields/enum'], Dep => class extends Dep {
    listTemplate = 'custom:contact/fields/lead-status-list';

    // Keep presentation keyed to stable enum values so translated labels never affect styling.
    statusClasses = {
        New: 'new',
        Open: 'open',
        InProgress: 'in-progress',
        OpenDeal: 'open-deal',
        Unqualified: 'unqualified',
        AttemptedToContact: 'attempted',
        Connected: 'connected',
        BadTiming: 'bad-timing',
    };

    data() {
        const data = super.data();
        const value = this.model.get(this.name) || '';

        return {
            ...data,
            hasStatus: Boolean(value),
            statusClass: this.statusClasses[value] || 'other',
        };
    }
});
