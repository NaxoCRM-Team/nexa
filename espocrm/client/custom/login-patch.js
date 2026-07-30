require(['views/login', 'views/user/password-change-request', 'app'], (LoginView, PasswordResetView, App) => {
    // Espo publishes the route-relative asset base in the loader contract.
    // Reuse it so custom API and provider requests also escape /login/.
    const loaderBasePath = (() => {
        try {
            const source = document.querySelector('script[data-name="loader-params"]')?.textContent;
            return source ? JSON.parse(source).basePath || '' : '';
        } catch {
            return '';
        }
    })();
    const applicationBaseUrl = new URL(loaderBasePath || './', location.href);
    const applicationUrl = path => new URL(String(path).replace(/^\/+/, ''), applicationBaseUrl);
    const replaceUrl = url => history.replaceState(null, '', url.pathname + url.search + url.hash);
    const showApplicationUrl = () => {
        const url = new URL(applicationBaseUrl);
        url.hash = location.hash;
        replaceUrl(url);
    };
    const showLoginUrl = () => {
        const url = applicationUrl('login/');
        url.search = location.search;
        replaceUrl(url);
    };
    const defaultOnAuth = App.prototype.onAuth;
    const defaultData = LoginView.prototype.data;
    const defaultSetup = LoginView.prototype.setup;
    const defaultAfterRender = LoginView.prototype.afterRender;
    const defaultResetSetup = PasswordResetView.prototype.setup;

    // Normalize the pathname before Espo initializes Backbone history. Otherwise
    // every authenticated route inherits /login/ as its permanent router root.
    App.prototype.onAuth = async function (...args) {
        showApplicationUrl();

        return defaultOnAuth.apply(this, args);
    };

    LoginView.prototype.data = function () {
        return {
            ...defaultData.call(this),
            applicationName: 'Nexa CRM',
            // Nexa owns recovery through its tenant-aware endpoint, so the entry
            // point must not depend on EspoCRM's legacy SMTP-derived UI flag.
            showForgotPassword: true,
        };
    };

    LoginView.prototype.setup = function () {
        this.template = 'custom:login-modern';
        defaultSetup.call(this);

        this.once('remove', () => {
            document.body.classList.remove('modern-login-page');
        });
    };

    LoginView.prototype.afterRender = function () {
        defaultAfterRender.call(this);
        showLoginUrl();
        document.body.classList.add('modern-login-page');

        const loginPanel = this.element.querySelector('#login');
        const recoveryPanel = this.element.querySelector('[data-recovery-panel]');
        const recoveryForm = this.element.querySelector('[data-recovery-form]');
        const recoveryMessage = this.element.querySelector('[data-recovery-message]');
        let recoveryMessageTimer;
        const dismissRecoveryMessage = () => {
            window.clearTimeout(recoveryMessageTimer);
            recoveryMessageTimer = undefined;
            recoveryMessage.hidden = true;
            recoveryMessage.textContent = '';
            recoveryMessage.classList.remove('is-error', 'is-success', 'error');
        };
        const showRecoveryMessage = (text, isError) => {
            dismissRecoveryMessage();
            recoveryMessage.textContent = text;
            recoveryMessage.classList.add(isError ? 'is-error' : 'is-success');
            recoveryMessage.hidden = false;
            recoveryMessageTimer = window.setTimeout(dismissRecoveryMessage, isError ? 10000 : 7000);
        };
        const showLoginError = text => {
            const message = this.element.querySelector('[data-login-message]');
            message.textContent = text;
            message.classList.remove('is-success');
            message.classList.add('is-error');
            message.hidden = false;
        };
        const showLogin = () => {
            dismissRecoveryMessage();
            recoveryPanel.hidden = true;
            loginPanel.hidden = false;
            window.setTimeout(() => this.element.querySelector('#field-userName')?.focus(), 0);
        };
        // OAuth returns the short-lived Espo token in the URL fragment so it is
        // never sent in referrers or server access logs.
        const socialPayload = location.hash.startsWith('#nexa-social=')
            ? location.hash.slice('#nexa-social='.length)
            : null;
        if (socialPayload) {
            history.replaceState(null, '', location.pathname + location.search);
            try {
                const padded = socialPayload.replace(/-/g, '+').replace(/_/g, '/')
                    .padEnd(Math.ceil(socialPayload.length / 4) * 4, '=');
                const social = JSON.parse(decodeURIComponent(escape(atob(padded))));
                const authorization = btoa(social.userName + ':' + social.token);
                this.disableForm();
                Espo.Ajax.getRequest('App/user', null, {
                    login: true,
                    headers: {
                        Authorization: 'Basic ' + authorization,
                        'Espo-Authorization': authorization,
                        'Espo-Authorization-By-Token': 'true',
                    },
                }).then(data => this.triggerLogin(social.userName, data))
                    .catch(() => {
                        this.undisableForm();
                        showLoginError('Google sign in could not be completed. Please try again.');
                    });
            } catch (error) {
                showLoginError('Google sign in could not be completed. Please try again.');
            }
        }
        const socialError = new URLSearchParams(location.search).get('socialError');
        if (socialError) {
            showLoginError(socialError === 'social_account_not_linked'
                ? 'No Nexa account is connected to that identity. Sign in with your password or contact your workspace administrator.'
                : 'Identity-provider sign in was cancelled or could not be completed.');
        }

        this.element.querySelector('[data-action="nexaRecovery"]')?.addEventListener('click', event => {
            event.preventDefault();
            loginPanel.hidden = true;
            recoveryPanel.hidden = false;
            window.setTimeout(() => recoveryForm.elements.email.focus(), 0);
        });
        this.element.querySelector('[data-recovery-back]')?.addEventListener('click', showLogin);
        if (new URLSearchParams(location.search).get('recovery') === '1') {
            loginPanel.hidden = true;
            recoveryPanel.hidden = false;
            window.setTimeout(() => recoveryForm.elements.email.focus(), 0);
        }

        recoveryForm?.addEventListener('submit', async event => {
            event.preventDefault();
            if (!recoveryForm.reportValidity()) return;
            const submit = recoveryForm.querySelector('[type="submit"]');
            submit.disabled = true;
            dismissRecoveryMessage();
            try {
                const response = await fetch(applicationUrl('api/v1/Nexa/auth/recovery'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(Object.fromEntries(new FormData(recoveryForm))),
                });
                const body = await response.json().catch(() => ({}));
                showRecoveryMessage(
                    response.ok ? body.message : body.message || 'We could not process the request. Try again.',
                    !response.ok
                );
                if (response.ok) recoveryForm.reset();
            } catch (error) {
                showRecoveryMessage('We could not process the request. Try again.', true);
            } finally {
                submit.disabled = false;
            }
        });

        fetch(applicationUrl('api/v1/Nexa/auth/providers'), {credentials: 'same-origin'})
            .then(response => response.ok ? response.json() : {providers: []})
            .then(({providers = []}) => {
                const target = this.element.querySelector('[data-auth-providers]');
                const divider = this.element.querySelector('[data-auth-divider]');
                if (!target || providers.length === 0) return;
                providers.forEach(provider => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'modern-social-button modern-social-button--' + provider.key;
                    button.innerHTML = provider.key === 'google'
                        ? `<img class="google-auth-icon" src="${applicationUrl('client/custom/img/google-g.svg')}" alt=""><span>Continue with Google</span>`
                        : '<span class="fab fa-' + provider.icon + '" aria-hidden="true"></span><span>Continue with ' + provider.label + '</span>';
                    button.addEventListener('click', () => {
                        const url = applicationUrl(provider.startUrl);
                        url.searchParams.set('intent', 'login');
                        location.assign(url);
                    });
                    target.append(button);
                });
                target.hidden = false;
                divider.hidden = false;
            })
            .catch(() => {});
    };

    PasswordResetView.prototype.setup = function () {
        this.template = 'custom:password-reset';
        defaultResetSetup.call(this);
        document.body.classList.add('modern-login-page', 'modern-reset-page');
        this.once('remove', () => document.body.classList.remove('modern-login-page', 'modern-reset-page'));
    };
});
