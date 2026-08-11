define('custom:views/contact/fields/location-list', ['views/fields/address'], Dep => class extends Dep {
    listTemplate = 'custom:contact/fields/location-list';

    data() {
        const data = super.data();
        const city = this.model.get('addressCity')?.trim() || '';
        const state = this.model.get('addressState')?.trim() || '';
        const country = this.model.get('addressCountry')?.trim() || '';
        const countryCodes = this.getHelper().getAppParam('addressSubdivisionData')?.countryCodes || {};
        const code = countryCodes[country] || '';
        const location = [city, state].filter(Boolean).join(', ') || country;

        return {
            ...data,
            country,
            flagAlt: country ? `${country} flag` : '',
            flagUrl: code ? `${this.getBasePath()}client/custom/img/flags/4x3/${code.toLowerCase()}.svg` : '',
            hasLocation: Boolean(location),
            location,
        };
    }
});
