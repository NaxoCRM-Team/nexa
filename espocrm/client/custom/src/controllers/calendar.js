define('custom:controllers/calendar', ['crm:controllers/calendar'], Dep => class extends Dep {
    actionIndex(options = {}) {
        this.handleCheckAccess('');
        this.main('custom:views/calendar/calendar-page', {
            date: options.date,
            mode: options.mode,
            userId: options.userId,
            userName: options.userName,
        });
    }
});
