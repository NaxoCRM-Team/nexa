define(['views/email/record/compose'], Compose => {
    return class extends Compose {
        errorHandlerSendingFail(data) {
            super.errorHandlerSendingFail(data);
            // The failed transport save created a Draft revision, not another user's edit.
            // Keep unsaved editor content; a later intervening edit must still conflict.
            if (Number.isSafeInteger(data.versionNumber) && data.versionNumber > 0) {
                this.model.set('versionNumber', data.versionNumber);
            }
        }
    };
});
