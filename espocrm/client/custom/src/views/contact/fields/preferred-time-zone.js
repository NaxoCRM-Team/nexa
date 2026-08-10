define('custom:views/contact/fields/preferred-time-zone', ['views/preferences/fields/time-zone'], Dep => {
    /**
     * Espo's enum control already filters as a user types. This Contact-specific
     * view makes that search input explicit whenever the long timezone menu opens.
     */
    return class extends Dep {
        afterRender() {
            super.afterRender();

            if (!this.isEditMode()) {
                return;
            }

            const control = this.$element?.get(0)?.selectize;

            if (!control) {
                return;
            }

            const input = control.$control_input;
            input.attr({
                'aria-label': 'Search time zones',
                'placeholder': 'Search time zones',
            });

            control.on('dropdown_open', () => {
                input.attr('placeholder', 'Search time zones');
                control.$control.addClass('nexa-time-zone-search-open');
                input.trigger('focus');
            });
            control.on('dropdown_close', () => {
                control.$control.removeClass('nexa-time-zone-search-open');
            });
            control.updatePlaceholder();
        }
    };
});
