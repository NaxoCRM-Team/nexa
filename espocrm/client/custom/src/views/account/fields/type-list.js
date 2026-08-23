define('custom:views/account/fields/type-list', ['views/fields/enum'], Dep => class extends Dep {
    listTemplate = 'custom:account/fields/type-list';

    data() {
        const data = super.data();
        const value = this.model.get(this.name);
        const statusClass = {Customer: 'customer', Partner: 'partner', Reseller: 'reseller', Investor: 'investor'}[value] || 'other';

        return {...data, value, statusClass, hasValue: Boolean(value)};
    }
});
