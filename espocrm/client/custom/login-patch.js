require(['views/login', 'views/user/password-change-request', 'app', 'backbone'], (LoginView, PasswordResetView, App, Backbone) => {
    // Espo publishes the route-relative asset base in the loader contract.
    // Reuse it so every route remains portable under /nexa or another mount.
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
    const mountPath = applicationBaseUrl.pathname.endsWith('/')
        ? applicationBaseUrl.pathname
        : applicationBaseUrl.pathname + '/';
    const replaceUrl = url => history.replaceState(null, '', url.pathname + url.search + url.hash);
    const decodeRouteSegment = value => {
        try {
            return decodeURIComponent(value);
        } catch {
            return value;
        }
    };
    const workspaceLocation = () => {
        if (!location.pathname.startsWith(mountPath + 'w/')) {
            return null;
        }

        const parts = location.pathname.slice((mountPath + 'w/').length).split('/');
        const slug = decodeRouteSegment(parts.shift() || '').toLowerCase();

        if (!slug) {
            return null;
        }

        return {
            slug,
            fragment: parts.filter(Boolean).map(decodeRouteSegment).join('/'),
        };
    };
    const requestedWorkspace = workspaceLocation();
    const encodeFragment = fragment => String(fragment || '')
        .replace(/^#+|\/+$/g, '')
        .split('/')
        .filter(Boolean)
        .map(encodeURIComponent)
        .join('/');
    const workspaceUrl = (slug, fragment = '') => {
        const url = applicationUrl('w/' + encodeURIComponent(slug));
        const encodedFragment = encodeFragment(fragment);

        if (encodedFragment) {
            url.pathname += '/' + encodedFragment;
        }

        return url;
    };
    let activeRouter = null;
    let activeWorkspaceBaseUrl = null;
    const showApplicationUrl = app => {
        const tenant = app.appParams.get('nexaTenant');

        if (!tenant?.slug) {
            throw new Error('Authenticated workspace identity is unavailable.');
        }

        const currentWorkspace = workspaceLocation() || requestedWorkspace;
        const legacyFragment = location.hash.replace(/^#/, '');
        const fragment = legacyFragment || currentWorkspace?.fragment || '';
        activeWorkspaceBaseUrl = workspaceUrl(tenant.slug);
        replaceUrl(workspaceUrl(tenant.slug, fragment));

        return activeWorkspaceBaseUrl;
    };
    const showLoginUrl = (preservedHash = '') => {
        if (Backbone.History.started) {
            Backbone.history.stop();
        }

        activeRouter = null;
        activeWorkspaceBaseUrl = null;
        const url = applicationUrl('login/');
        const parameters = new URLSearchParams(location.search);
        parameters.delete('login');
        parameters.delete('source');
        url.search = parameters.toString();
        url.hash = preservedHash;
        replaceUrl(url);
    };

    // Espo still emits hash-shaped href values. Route them through Backbone's
    // push-state navigator so the address bar stays tenant-qualified and clean.
    document.addEventListener('click', event => {
        if (!activeRouter || !activeWorkspaceBaseUrl ||
            !location.pathname.startsWith(activeWorkspaceBaseUrl.pathname) ||
            event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        const anchor = event.target.closest?.('a[href^="#"]');
        const href = anchor?.getAttribute('href');

        if (!href) {
            return;
        }

        event.preventDefault();
        if (href === '#') {
            activeRouter.navigate('', {trigger: true});
            return;
        }
        activeRouter.navigate(href.slice(1), {trigger: true});
    }, true);
    const defaultInitRouter = App.prototype.initRouter;
    const defaultData = LoginView.prototype.data;
    const defaultSetup = LoginView.prototype.setup;
    const defaultAfterRender = LoginView.prototype.afterRender;
    const defaultResetSetup = PasswordResetView.prototype.setup;

    LoginView.prototype.showNexaLoginError = function (text) {
        const message = this.element.querySelector('[data-login-message]');

        if (!message) return;

        message.textContent = text;
        message.classList.remove('is-success');
        message.classList.add('is-error');
        message.hidden = false;
        message.setAttribute('tabindex', '-1');
        message.focus({preventScroll: true});
        message.scrollIntoView({behavior: 'smooth', block: 'nearest'});

        window.clearTimeout(this.nexaLoginErrorTimer);
        this.nexaLoginErrorTimer = window.setTimeout(() => {
            this.clearNexaLoginError();
        }, 8000);
    };

    LoginView.prototype.clearNexaLoginError = function () {
        const message = this.element.querySelector('[data-login-message]');

        window.clearTimeout(this.nexaLoginErrorTimer);
        this.nexaLoginErrorTimer = null;

        if (!message) return;

        message.hidden = true;
        message.textContent = '';
        message.classList.remove('is-error', 'is-success');
        message.removeAttribute('tabindex');
        this.element.querySelectorAll('#login-form .has-error').forEach(cell => {
            cell.classList.remove('has-error');
        });
        this.element.querySelectorAll('#login-form [aria-invalid="true"]').forEach(input => {
            input.removeAttribute('aria-invalid');
        });
    };

    LoginView.prototype.onFail = function (messageKey) {
        Espo.Ui.notify(false);
        const message = messageKey === 'wrongUsernamePassword'
            ? 'The email address or password is incorrect. Check your details and try again.'
            : 'We could not sign you in right now. Please try again.';

        this.element.querySelectorAll('#login-form .form-group').forEach(cell => {
            cell.classList.add('has-error');
        });
        this.element.querySelectorAll('#field-userName, #field-password').forEach(input => {
            input.setAttribute('aria-invalid', 'true');
        });
        this.showNexaLoginError(message);
    };

    App.prototype.initRouter = function (...args) {
        const workspaceBaseUrl = showApplicationUrl(this);
        const defaultHistoryStart = Backbone.history.start;

        Backbone.history.start = function (options = {}) {
            return defaultHistoryStart.call(this, {
                ...options,
                root: workspaceBaseUrl.pathname + '/',
                pushState: true,
                hashChange: false,
            });
        };

        try {
            const result = defaultInitRouter.apply(this, args);
            activeRouter = this.router;

            return result;
        } finally {
            Backbone.history.start = defaultHistoryStart;
        }
    };
    LoginView.prototype.data = function () {
        return {
            ...defaultData.call(this),
            applicationName: 'Nexa CRM',
            socialConnecting: location.hash.startsWith('#nexa-social='),
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
        const socialHash = location.hash.startsWith('#nexa-social=') ? location.hash : '';
        showLoginUrl(socialHash);
        document.body.classList.add('modern-login-page');
        this.element.querySelectorAll('[data-nexa-current-year]').forEach(element => {
            element.textContent = String(new Date().getFullYear());
        });

        const loginPanel = this.element.querySelector('#login');
        const socialConnectingPanel = this.element.querySelector('[data-social-connecting]');
        const socialConnectingMessage = this.element.querySelector('[data-social-connecting-message]');
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
            socialConnectingPanel.hidden = true;
            loginPanel.hidden = false;
            this.showNexaLoginError(text);
        };
        const showLogin = () => {
            dismissRecoveryMessage();
            recoveryPanel.hidden = true;
            loginPanel.hidden = false;
            window.setTimeout(() => this.element.querySelector('#field-userName')?.focus(), 0);
        };        this.element.querySelectorAll('[data-action="nexaHome"]').forEach(link => {
            link.addEventListener('click', event => {
                event.preventDefault();
                location.assign(applicationBaseUrl.href);
            });
        });
        // OAuth returns the short-lived Espo token in the URL fragment so it is
        // never sent in referrers or server access logs.
        const socialPayload = socialHash
            ? socialHash.slice('#nexa-social='.length)
            : null;
        if (socialPayload) {
            history.replaceState(null, '', location.pathname + location.search);
            try {
                const padded = socialPayload.replace(/-/g, '+').replace(/_/g, '/')
                    .padEnd(Math.ceil(socialPayload.length / 4) * 4, '=');
                const social = JSON.parse(decodeURIComponent(escape(atob(padded))));
                const authorization = btoa(social.userName + ':' + social.token);
                const providerLabel = social.provider === 'microsoft' ? 'Microsoft' : 'Google';
                socialConnectingPanel.hidden = false;
                loginPanel.hidden = true;
                socialConnectingMessage.textContent = `We are securely connecting your ${providerLabel} identity to the correct Nexa workspace.`;
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
                        showLoginError(`${providerLabel} sign in could not be completed. Please try again.`);
                    });
            } catch (error) {
                showLoginError('Social sign in could not be completed. Please try again.');
            }
        }
        const socialParameters = new URLSearchParams(location.search);
        const socialError = socialParameters.get('socialError');
        if (socialError) {
            const providerLabel = socialParameters.get('socialProvider') === 'microsoft' ? 'Microsoft' : 'Google';
            showLoginError(socialError === 'social_account_not_linked'
                ? `No Nexa account is connected to that ${providerLabel} account. Use another account, sign in with your password, or create a workspace.`
                : `${providerLabel} sign in was cancelled or could not be completed.`);

            const cleanUrl = new URL(location.href);
            cleanUrl.searchParams.delete('socialError');
            cleanUrl.searchParams.delete('socialProvider');
            history.replaceState(null, '', cleanUrl.pathname + cleanUrl.search);
        }

        this.element.querySelectorAll('#field-userName, #field-password').forEach(input => {
            input.addEventListener('input', () => {
                if (!this.element.querySelector('[data-login-message]')?.hidden) {
                    this.clearNexaLoginError();
                }
            });
        });

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
