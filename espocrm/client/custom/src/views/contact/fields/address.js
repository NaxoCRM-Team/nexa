define('custom:views/contact/fields/address', ['views/fields/address', 'ui/autocomplete'], (Dep, AutocompleteModule) => {
    const Autocomplete = AutocompleteModule.default || AutocompleteModule;

    /**
     * Adds country-aware region suggestions while preserving free-text entry.
     * This matters for territories whose administrative divisions change or
     * do not use the words state or province.
     */
    return class extends Dep {
        afterRender() {
            super.afterRender();

            if (!this.isEditMode() || !this.$state?.length) {
                return;
            }

            const data = this.getHelper().getAppParam('addressSubdivisionData') || {};
            const byCountry = data.byCountry || {};
            const allRegions = [...new Set(Object.values(byCountry).flat())]
                .sort((a, b) => a.localeCompare(b));

            const lookupFunction = query => {
                const country = this.$country.val()?.toString().trim();
                const source = byCountry[country] || allRegions;
                const normalized = query.toLowerCase();
                const matches = source
                    .filter(region => region.toLowerCase().includes(normalized))
                    .slice(0, 80)
                    .map(value => ({value}));

                return Promise.resolve(matches);
            };

            const autocomplete = new Autocomplete(this.$state.get(0), {
                name: `${this.name}State`,
                triggerSelectOnValidInput: true,
                autoSelectFirst: true,
                handleFocusMode: 1,
                focusOnSelect: true,
                lookup: [],
                lookupFunction,
                onSelect: () => this.trigger('change'),
            });

            this.once('render remove', () => autocomplete.dispose());
        }
    };
});
