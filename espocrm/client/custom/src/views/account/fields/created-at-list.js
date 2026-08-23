define('custom:views/account/fields/created-at-list', ['views/fields/datetime'], Dep => class extends Dep {
    getDateStringValue() {
        const value = this.model.get(this.name);
        if (!value) return super.getDateStringValue();

        const date = this.getDateTime().toMoment(value);
        const configuredFormat = this.getDateTime().getDateFormat();
        const yearFormat = configuredFormat.includes('Y') ? configuredFormat : `${configuredFormat}/YYYY`;

        return `${date.format(yearFormat)} ${date.format(this.getDateTime().timeFormat)}`;
    }
});
