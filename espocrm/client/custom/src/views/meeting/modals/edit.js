define('custom:views/meeting/modals/edit', ['views/modals/edit'], Dep => {
    return class extends Dep {
        className = 'dialog dialog-record nexa-meeting-modal';
    };
});
