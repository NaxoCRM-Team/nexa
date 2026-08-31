define('custom:views/calendar/calendar-page', ['crm:views/calendar/calendar-page'], Dep => class extends Dep {
    template = 'custom:calendar/calendar-page';

    setup() {
        super.setup();
        this.once('remove', () => this.element?.classList.remove('nexa-native-calendar-page'));
    }

    afterRender() {
        const result = super.afterRender();
        this.element?.classList.add('nexa-native-calendar-page');
        return result;
    }
});
