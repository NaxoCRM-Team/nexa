define(['views/lead-capture/form'], Dep => class extends Dep {
    setup() {
        super.setup();
        document.body.classList.add('nexa-public-form');
        this.isLandingFrame = new URLSearchParams(window.location.search).get('nexaFrame') === '1';
        if (this.isLandingFrame) {
            document.body.classList.add('nexa-landing-form-frame');
        }
        this.once('remove', () => document.body.classList.remove('nexa-public-form', 'nexa-landing-form-frame'));
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
        this.whenReady().then(() => this.initializeFormIntelligence());
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
        this.rememberCompletedFields();
        this.isPosted = true;
        this.isPosting = false;
        this.recordView.remove();
        await this.reRender();

        if (this.isLandingFrame && window.parent !== window) {
            window.parent.postMessage({
                type: 'nexa:form-submitted',
                successText: String(this.formData.successText || 'Thank you. Your response has been received.'),
                redirectUrl: result.redirectUrl || null,
                redirectDelaySeconds: Math.max(1, Math.min(30, Number(this.formData.nexaRedirectDelaySeconds || 4))),
            }, window.location.origin);

            return;
        }

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

    initializeFormIntelligence() {
        const rules = this.formData.nexaConditionalRules || [];
        const protectedFields = new Set(rules.flatMap(rule => [rule.sourceField, rule.targetField]));
        const requiredFields = new Set(this.formData.nexaRequiredFields || []);

        if (this.formData.nexaProgressiveProfiling) {
            this.completedFields().forEach(field => {
                if (!requiredFields.has(field) && !protectedFields.has(field)) this.recordView.hideField(field);
            });
        }

        this.listenTo(this.model, 'change', () => this.applyConditionalRules());
        this.applyConditionalRules();
    }

    applyConditionalRules() {
        const requiredFields = new Set(this.formData.nexaRequiredFields || []);
        const rulesByTarget = new Map();

        (this.formData.nexaConditionalRules || []).forEach(rule => {
            if (!rulesByTarget.has(rule.targetField)) rulesByTarget.set(rule.targetField, []);
            rulesByTarget.get(rule.targetField).push(rule);
        });

        rulesByTarget.forEach((rules, target) => {
            const visible = rules.every(rule => this.conditionMatches(this.model.get(rule.sourceField), rule.operator, rule.value));
            if (visible) {
                this.recordView.showField(target);
                if (requiredFields.has(target)) this.recordView.setFieldRequired(target);
            } else {
                this.recordView.hideField(target);
                this.recordView.setFieldNotRequired(target);
            }
        });
    }

    conditionMatches(actual, operator, expected) {
        const actualText = String(Array.isArray(actual) ? actual.join(' ') : (actual ?? '')).trim().toLowerCase();
        const expectedText = String(expected ?? '').trim().toLowerCase();

        if (operator === 'notEquals') return actualText !== expectedText;
        if (operator === 'contains') return expectedText !== '' && actualText.includes(expectedText);
        if (operator === 'isEmpty') return actualText === '';
        if (operator === 'isNotEmpty') return actualText !== '';
        return actualText === expectedText;
    }

    profileStorageKey() {
        return `nexa-form-profile-${this.formData.nexaFormId || 'public'}`;
    }

    completedFields() {
        try {
            const fields = JSON.parse(window.localStorage.getItem(this.profileStorageKey()) || '[]');
            return Array.isArray(fields) ? fields : [];
        } catch {
            return [];
        }
    }

    rememberCompletedFields() {
        if (!this.formData.nexaProgressiveProfiling) return;
        const completed = new Set(this.completedFields());
        (this.formData.nexaFieldList || []).forEach(field => {
            const value = this.model.get(field);
            if (value !== null && value !== undefined && value !== '' && (!Array.isArray(value) || value.length)) completed.add(field);
        });

        try {
            window.localStorage.setItem(this.profileStorageKey(), JSON.stringify([...completed]));
        } catch {
            // The form remains usable when browser storage is unavailable.
        }
    }
});
