define(['views/lead-capture/form'], Dep => class extends Dep {
    setup() {
        super.setup();
        document.body.classList.add('nexa-public-form');
        this.once('remove', () => document.body.classList.remove('nexa-public-form'));
        const defaults = this.formData.nexaSubmissionDefaults || {};
        let visitorId = null;

        try {
            visitorId = window.localStorage.getItem('nexa-form-visitor-id');
            if (!visitorId) {
                visitorId = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
                window.localStorage.setItem('nexa-form-visitor-id', visitorId);
            }
        } catch (e) {
            visitorId = null;
        }

        this.model.set({...defaults, nexaVisitorId: visitorId});
    }

    async actionCreate() {
        if (this.isPosting) return;
        if (this.recordView.validate()) {
            Espo.Ui.error(this.translate('Not valid'));
            return;
        }

        this.isPosting = true;
        this.recordView.disableActionItems();
        this.submitButtonElement.classList.add('disabled');
        this.submitButtonElement.setAttribute('disabled', 'disabled');
        Espo.Ui.notifyWait();
        const token = await this.processCaptcha();
        const headers = token ? {'X-Captcha-Token': token} : undefined;
        let result;

        try {
            result = await Espo.Ajax.postRequest(this.model.url, this.model.attributes, {headers});
        } catch (error) {
            this.recordView.enableActionItems();
            this.submitButtonElement.classList.remove('disabled');
            this.submitButtonElement.removeAttribute('disabled');
            this.isPosting = false;
            return;
        }

        Espo.Ui.notify();
        this.isPosted = true;
        this.isPosting = false;
        this.recordView.remove();
        await this.reRender();

        if (result.redirectUrl) await this.redirectAfterSuccess(result.redirectUrl);
    }

    async redirectAfterSuccess(url) {
        let remaining = Math.max(1, Math.min(30, Number(this.formData.nexaRedirectDelaySeconds || 4)));
        const notice = document.createElement('p');
        notice.className = 'nexa-form-redirect-notice';
        notice.setAttribute('role', 'status');
        notice.setAttribute('aria-live', 'polite');
        this.element.append(notice);

        while (remaining > 0) {
            notice.textContent = `Continuing in ${remaining} ${remaining === 1 ? 'second' : 'seconds'}...`;
            await new Promise(resolve => setTimeout(resolve, 1000));
            remaining -= 1;
        }

        document.location.href = url;
    }
});
