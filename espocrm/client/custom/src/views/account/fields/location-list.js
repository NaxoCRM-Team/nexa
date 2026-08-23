define('custom:views/account/fields/location-list', ['views/fields/address'], Dep => class extends Dep {
    listTemplate = 'custom:account/fields/location-list';

    data() {
        const data = super.data();
        const city = this.model.get('billingAddressCity')?.trim() || '';
        const state = this.model.get('billingAddressState')?.trim() || '';
        const country = this.model.get('billingAddressCountry')?.trim() || '';
        const countryCodes = this.getHelper().getAppParam('addressSubdivisionData')?.countryCodes || {};
        const code = countryCodes[country];

        return {
            ...data,
            country,
            flagAlt: country ? `${country} flag` : '',
            flagUrl: code ? `${this.getBasePath()}client/custom/img/flags/4x3/${String(code).toLowerCase()}.svg` : '',
            hasLocation: Boolean(city || state || country),
            location: [city, state].filter(Boolean).join(', ') || country,
        };
    }
});
