define('custom:views/lead/fields/name', ['views/fields/person-name'], Dep => class extends Dep {
    listTemplate = 'custom:lead/fields/name/list-link';
    listLinkTemplate = 'custom:lead/fields/name/list-link';

    data() {
        const data = super.data();
        const displayName = this.getFormattedValue() || this.model.get('name') || 'Unnamed Lead';
        data.displayName = displayName;
        data.initials = String(displayName).split(/\s+/).filter(Boolean).slice(0, 2)
            .map(part => part.charAt(0).toUpperCase()).join('') || '?';
        return data;
    }
});
