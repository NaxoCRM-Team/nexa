define('custom:views/account/fields/name-list', ['views/fields/varchar'], Dep => class extends Dep {
    listTemplate = 'custom:account/fields/name-list';
    listLinkTemplate = 'custom:account/fields/name-list';

    data() {
        const data = super.data();
        const displayName = this.model.get(this.name) || 'Unnamed account';

        return {...data, displayName, initial: String(displayName).trim().charAt(0).toUpperCase() || '?'};
    }
});
