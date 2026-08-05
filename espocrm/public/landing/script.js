(() => {
    const applicationBaseUrl = new URL(document.querySelector('base')?.href || './', location.href);
    const applicationUrl = path => new URL(String(path).replace(/^\/+/, ''), applicationBaseUrl);
    const header = document.querySelector('[data-header]');
    const nav = document.querySelector('[data-nav]');
    const navToggle = document.querySelector('[data-nav-toggle]');

    const updateHeader = () => {
        header?.classList.toggle('scrolled', window.scrollY > 40);
    };

    updateHeader();
    window.addEventListener('scroll', updateHeader, {passive: true});

    navToggle?.addEventListener('click', () => {
        const isOpen = nav.classList.toggle('open');
        navToggle.setAttribute('aria-expanded', String(isOpen));
        navToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
        navToggle.querySelector('span')?.classList.toggle('fa-bars', !isOpen);
        navToggle.querySelector('span')?.classList.toggle('fa-times', isOpen);
    });

    nav?.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            nav.classList.remove('open');
            navToggle?.setAttribute('aria-expanded', 'false');
            navToggle?.setAttribute('aria-label', 'Open navigation');
            navToggle?.querySelector('span')?.classList.add('fa-bars');
            navToggle?.querySelector('span')?.classList.remove('fa-times');
        });
    });

    document.querySelectorAll('[data-billing]').forEach(button => {
        button.addEventListener('click', () => {
            const billing = button.dataset.billing;
            document.querySelectorAll('[data-billing]').forEach(item => item.classList.toggle('active', item === button));
            document.querySelectorAll('[data-price]').forEach(price => {
                price.textContent = price.dataset[billing];
            });
        });
    });

    // These values mirror the hard limits in the development plan catalog.
    // Keep both surfaces aligned when product packaging changes.
    const plans = [
        {key: 'launch', cta: 'Start 14-day trial', items: ['5 users and core CRM pipelines', '1,000 marketing contacts', '10,000 email sends per month', '25 automation workflows', '5 GB managed storage']},
        {key: 'growth', cta: 'Start 14-day trial', items: ['25 users and advanced CRM', '10,000 marketing contacts', '100,000 email sends per month', '300 automation workflows', '25 GB managed storage']},
        {key: 'scale', cta: 'Start 14-day trial', items: ['100 users and enterprise controls', '50,000 marketing contacts', '1,000,000 email sends per month', '1,000 automation workflows', '100 GB managed storage']},
    ];

    // This public catalog mirrors the 86-item product inventory and adds the
    // three SaaS foundations that make those functions safe for many tenants.
    const capabilityCatalog = [
        {
            key: 'crm', name: 'CRM & Revenue', icon: 'fa-chart-line',
            summary: 'Manage the full customer relationship, revenue process and account strategy.',
            items: ['Extensible product modules', 'Required fields', 'Multiple currencies', 'Teams and permission templates', 'Duplicate management', 'Association labels', 'Calculated properties', 'Collaboration tools', 'Reusable presets', 'CRM interface configuration', 'Deal and company scoring', 'Team organization and hierarchy', 'Custom objects', 'Target accounts home'],
        },
        {
            key: 'marketing', name: 'Marketing & Content', icon: 'fa-paper-plane',
            summary: 'Create, govern and deliver campaigns and content using live CRM context.',
            items: ['Email marketing tiers and limits', 'Marketing events object', 'Email health reporting', 'Email reply tracking', 'Programmable email', 'URL mappings and redirects', 'Video hosting and management', 'A/B testing', 'Multi-language content', 'Campaign management', 'Marketing SMS', 'Logged-in visitor identification', 'Email approval workflows', 'Marketing email hosting and domains', 'Marketing email single-send API'],
        },
        {
            key: 'automation', name: 'Automation & Personalization', icon: 'fa-bolt',
            summary: 'Turn signals into coordinated journeys, decisions and next-best actions.',
            items: ['Simple marketing automation', 'Unlimited-action email automation', 'Unlimited-action form automation', 'Personalization tokens', 'Simple ad automation', 'Smart email content', 'Omnichannel automation', 'Account-based marketing automation', 'Lead scoring models', 'Dynamic personalization', 'Standard contact scoring', 'Behavioral event triggers', 'Custom events', 'Visual event builder'],
        },
        {
            key: 'service', name: 'Conversations & Service', icon: 'fa-comments',
            summary: 'Meet customers across channels while preserving one conversation history.',
            items: ['Rebrandable live chat', 'Facebook Messenger integration', 'Conversational bots', 'Team email', 'Email and in-app support', 'One-to-one technical support', 'Shared inbox custom views', 'WhatsApp integration', 'Draggable chat widget'],
        },
        {
            key: 'analytics', name: 'Analytics & Attribution', icon: 'fa-chart-pie',
            summary: 'Connect activity to outcomes with reporting across campaigns and journeys.',
            items: ['Campaign reporting', 'Website traffic analytics', 'Custom reporting', 'Filtered analytics views', 'Marketing asset comparison', 'Contact creation attribution', 'SEO analytics', 'Customer journey analytics', 'Multi-touch revenue attribution'],
        },
        {
            key: 'channels', name: 'Social, Ads & SEO', icon: 'fa-bullhorn',
            summary: 'Coordinate reach, audiences and performance across acquisition channels.',
            items: ['Social publishing and scheduling', 'Ad management', 'SEO recommendations', 'Ad retargeting', 'AI social agent', 'Ad conversion events', 'AI social-inbox insights'],
        },
        {
            key: 'platform', name: 'Platform & Security', icon: 'fa-shield-alt',
            summary: 'Protect each workspace and give administrators precise operational control.',
            items: ['Complete Nexa rebranding', 'Cookie management tools', 'Mobile optimization', 'Subdomains and country-code domains', 'Social login', 'Permission sets', 'Content and data access limits', 'Audited user impersonation', 'Single sign-on', 'Admin notification management', 'Sandbox accounts', 'Sensitive-data protection', 'Field-level permissions', 'Tenant-isolated data access', 'Plan entitlements and usage controls', 'Audited platform administration'],
        },
        {
            key: 'integrations', name: 'Integrations & AI', icon: 'fa-plug',
            summary: 'Connect the customer platform to external data, intelligence and media services.',
            items: ['Salesforce integration', 'Google Search Console integration', 'Salesforce custom-object sync', 'Anthropic integration', 'YouTube analytics integration'],
        },
    ];

    const capabilityTabs = document.querySelector('[data-capability-tabs]');
    const capabilityList = document.querySelector('[data-capability-list]');
    const capabilityTitle = document.querySelector('[data-capability-title]');
    const capabilitySummary = document.querySelector('[data-capability-summary]');
    const capabilityCount = document.querySelector('[data-capability-count]');
    const capabilityIcon = document.querySelector('[data-capability-icon]');
    const capabilityTotal = capabilityCatalog.reduce((total, group) => total + group.items.length, 0);

    document.querySelectorAll('[data-capability-total]').forEach(node => {
        node.textContent = String(capabilityTotal);
    });

    // Render one domain at a time so the full scope remains readable on small
    // screens. Keyboard arrow navigation follows the ARIA tabs pattern.
    const selectCapabilityGroup = (group, focusTab = false) => {
        capabilityTabs?.querySelectorAll('[role="tab"]').forEach(tab => {
            const selected = tab.dataset.capabilityKey === group.key;
            tab.classList.toggle('active', selected);
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
            if (selected && focusTab) tab.focus();
        });
        capabilityTitle.textContent = group.name;
        capabilitySummary.textContent = group.summary;
        capabilityCount.textContent = String(group.items.length);
        capabilityIcon.className = `capability-panel-icon fas ${group.icon}`;
        capabilityList.innerHTML = group.items.map(item => `<li><span class="fas fa-check" aria-hidden="true"></span><span>${item}</span></li>`).join('');
    };

    if (capabilityTabs && capabilityList) {
        capabilityTabs.innerHTML = capabilityCatalog.map((group, index) => `
            <button type="button" role="tab" id="capability-tab-${group.key}" aria-controls="capability-panel" aria-selected="${index === 0}" tabindex="${index === 0 ? 0 : -1}" data-capability-key="${group.key}">
                <span class="fas ${group.icon}" aria-hidden="true"></span><span>${group.name}</span><small>${group.items.length}</small>
            </button>`).join('');
        capabilityTabs.addEventListener('click', event => {
            const tab = event.target.closest('[data-capability-key]');
            const group = capabilityCatalog.find(item => item.key === tab?.dataset.capabilityKey);
            if (group) selectCapabilityGroup(group);
        });
        capabilityTabs.addEventListener('keydown', event => {
            if (!['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const current = capabilityCatalog.findIndex(group => group.key === document.activeElement?.dataset.capabilityKey);
            const direction = ['ArrowDown', 'ArrowRight'].includes(event.key) ? 1 : -1;
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? capabilityCatalog.length - 1 : (current + direction + capabilityCatalog.length) % capabilityCatalog.length;
            selectCapabilityGroup(capabilityCatalog[next], true);
        });
        selectCapabilityGroup(capabilityCatalog[0]);
    }
    const dialog = document.querySelector('[data-signup-dialog]');
    const methodView = document.querySelector('[data-signup-method]');
    const emailStart = document.querySelector('[data-email-start]');
    const methodMessage = document.querySelector('[data-method-message]');
    const form = document.querySelector('[data-signup-form]');
    const formMessage = document.querySelector('[data-form-message]');
    const pending = document.querySelector('[data-signup-pending]');
    const verifying = document.querySelector('[data-signup-verifying]');
    const success = document.querySelector('[data-signup-success]');
    const verificationError = document.querySelector('[data-signup-error]');
    const verificationForm = document.querySelector('[data-verification-form]');
    const signupSocial = document.querySelector('[data-signup-social]');
    const signupDivider = document.querySelector('[data-signup-divider]');
    const emailFields = document.querySelector('[data-email-fields]');
    const socialIdentity = document.querySelector('[data-social-identity]');
    const stateViews = [methodView, form, pending, verifying, success, verificationError];
    let selectedPlan = 'growth';
    let attemptToken = '';
    let signupMethod = 'email';

    const setStep = step => document.querySelectorAll('[data-signup-step]').forEach(item => {
        item.classList.toggle('active', item.dataset.signupStep === step);
    });

    const showState = (target, step = 'method') => {
        stateViews.forEach(view => {
            if (view) view.hidden = view !== target;
        });
        setStep(step);
    };

    const setEmailFields = enabled => {
        emailFields.hidden = !enabled;
        emailFields.querySelectorAll('input').forEach(input => {
            input.required = enabled;
            input.disabled = !enabled;
        });
        socialIdentity.hidden = enabled;
    };

    const openDialog = (plan = 'growth') => {
        selectedPlan = plans.some(item => item.key === plan) ? plan : 'growth';
        form.elements.plan.value = selectedPlan;
        attemptToken = '';
        signupMethod = 'email';
        showState(methodView, 'method');
        if (!dialog.open) dialog.showModal();
        window.setTimeout(() => emailStart.elements.email.focus(), 50);
    };

    const showProfile = (method, token, plan = 'growth') => {
        signupMethod = method;
        attemptToken = token;
        selectedPlan = plans.some(item => item.key === plan) ? plan : 'growth';
        form.elements.plan.value = selectedPlan;
        setEmailFields(method === 'email');
        document.querySelector('[data-profile-help]').textContent = method === 'social'
            ? 'Your identity is verified. Confirm your name, company and selected plan.'
            : 'Add your name, company, password and selected plan.';
        showState(form, 'profile');
        if (!dialog.open) dialog.showModal();
        window.setTimeout(() => form.elements.company.focus(), 50);
    };

    const api = async (path, payload) => {
        const response = await fetch(applicationUrl(`api/v1/Nexa/signup${path}`), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw Object.assign(new Error(body.message || 'Something went wrong.'), {body});
        return body;
    };

    const encodeBase64 = value => {
        const bytes = new TextEncoder().encode(value);
        let binary = '';
        bytes.forEach(byte => binary += String.fromCharCode(byte));

        return btoa(binary);
    };

    const openAuthenticatedWorkspace = session => {
        if (!session?.userName || !session?.token) {
            throw new Error('Your workspace was created, but sign-in could not be completed.');
        }

        // Mirror Espo's successful login storage contract before loading the
        // application. The verification view stays visible until navigation.
        localStorage.removeItem('espo-user-anotherUser');
        localStorage.setItem(
            'espo-user-auth',
            encodeBase64(`${session.userName}:${session.token}`)
        );
        document.cookie = `auth-token=${session.token}; SameSite=Lax; path=/`;
        location.replace(applicationUrl('?login=1'));
    };

    const setLoading = (button, loading) => {
        button.disabled = loading;
        button.classList.toggle('loading', loading);
        button.setAttribute('aria-busy', String(loading));
    };

    const clearErrors = () => {
        formMessage.hidden = true;
        form.querySelectorAll('[data-error-for]').forEach(error => error.textContent = '');
        form.querySelectorAll('.invalid').forEach(field => field.classList.remove('invalid'));
    };

    const displayFieldErrors = error => {
        formMessage.textContent = error.message;
        formMessage.hidden = false;
        Object.entries(error.body?.fields || {}).forEach(([name, message]) => {
            const field = form.elements[name];
            if (field) field.classList.add('invalid');
            const target = form.querySelector(`[data-error-for="${name}"]`);
            if (target) target.textContent = message;
        });
        form.querySelector('.invalid:not([type="hidden"])')?.focus();
    };

    fetch(applicationUrl('api/v1/Nexa/auth/providers'), {credentials: 'same-origin'})
        .then(response => response.ok ? response.json() : {providers: []})
        .then(({providers = []}) => {
            providers.forEach(provider => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'social-auth-button social-auth-button--' + provider.key;
                button.innerHTML = provider.key === 'google'
                    ? `<img class="google-auth-icon" src="${applicationUrl('client/custom/img/google-g.svg')}" alt=""><span>Continue with Google</span>`
                    : '<span class="fab fa-' + provider.icon + '" aria-hidden="true"></span><span>Continue with ' + provider.label + '</span>';
                button.addEventListener('click', () => {
                    const target = applicationUrl(provider.startUrl);
                    target.searchParams.set('intent', 'signup');
                    target.searchParams.set('plan', selectedPlan);
                    location.assign(target);
                });
                signupSocial.append(button);
            });
            signupSocial.hidden = providers.length === 0;
            signupDivider.hidden = providers.length === 0;
        })
        .catch(() => {});

    // Enhance pricing actions while preserving their non-JavaScript links.
    document.querySelectorAll('.price-card').forEach((card, index) => {
        const plan = plans[index];
        if (!plan) return;
        const action = card.querySelector('.button');
        action.removeAttribute('href');
        action.setAttribute('role', 'button');
        action.setAttribute('tabindex', '0');
        action.dataset.signupPlan = plan.key;
        action.textContent = plan.cta;
        card.querySelector('ul').innerHTML = plan.items.map(item => `<li><span class="fas fa-check" aria-hidden="true"></span>${item}</li>`).join('');
        const currency = card.querySelector('.currency');
        if (currency) currency.innerHTML = '&pound;';
    });

    document.querySelectorAll('[data-signup-plan]').forEach(action => {
        const activate = event => {
            event.preventDefault();
            openDialog(action.dataset.signupPlan);
        };
        action.addEventListener('click', activate);
        action.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') activate(event);
        });
    });

    document.querySelector('[data-signup-close]')?.addEventListener('click', () => dialog.close());
    dialog?.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });

    emailStart?.addEventListener('submit', async event => {
        event.preventDefault();
        methodMessage.hidden = true;
        const emailError = document.querySelector('[data-email-start-error]');
        emailError.textContent = '';
        if (!emailStart.reportValidity()) return;
        const submit = emailStart.querySelector('[type="submit"]');
        setLoading(submit, true);
        try {
            const result = await api('', {email: emailStart.elements.email.value.trim(), plan: selectedPlan});
            showProfile('email', result.attemptToken, result.plan || selectedPlan);
        } catch (error) {
            methodMessage.textContent = error.message;
            methodMessage.hidden = false;
            emailError.textContent = error.body?.fields?.email || '';
        } finally {
            setLoading(submit, false);
        }
    });

    form?.elements.plan.addEventListener('change', event => {
        selectedPlan = event.currentTarget.value;
    });

    document.querySelector('[data-signup-back]')?.addEventListener('click', () => {
        if (signupMethod === 'social') {
            attemptToken = '';
        }
        showState(methodView, 'method');
        window.setTimeout(() => emailStart.elements.email.focus(), 50);
    });

    form?.addEventListener('submit', async event => {
        event.preventDefault();
        clearErrors();
        if (!form.reportValidity()) return;
        const values = Object.fromEntries(new FormData(form));
        const payload = {
            ...values,
            attemptToken,
            terms: form.elements.terms.checked,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
        };
        const submit = form.querySelector('[type="submit"]');
        setLoading(submit, true);
        try {
            const result = await api('/complete', payload);
            if (result.status === 'active' && result.session) {
                showState(verifying, 'verify');
                openAuthenticatedWorkspace(result.session);
                return;
            }
            document.querySelector('[data-pending-email]').textContent = result.email;
            showState(pending, 'verify');
            window.setTimeout(() => verificationForm.elements.code.focus(), 50);
        } catch (error) {
            displayFieldErrors(error);
        } finally {
            setLoading(submit, false);
        }
    });

    document.querySelector('[data-resend]')?.addEventListener('click', async event => {
        const message = document.querySelector('[data-resend-message]');
        event.currentTarget.disabled = true;
        message.classList.remove('is-error', 'is-success');
        try {
            const result = await api('/resend', {attemptToken});
            message.textContent = result.message;
            message.classList.add('is-success');
        } catch (error) {
            message.textContent = error.message;
            message.classList.add('is-error');
        } finally {
            event.currentTarget.disabled = false;
        }
    });

    verificationForm?.addEventListener('submit', async event => {
        event.preventDefault();
        if (!verificationForm.reportValidity()) return;
        const submit = verificationForm.querySelector('[type="submit"]');
        const message = document.querySelector('[data-code-message]');
        setLoading(submit, true);
        message.hidden = true;
        showState(verifying, 'verify');
        try {
            const result = await api('/verify', {attemptToken, code: verificationForm.elements.code.value});
            openAuthenticatedWorkspace(result.session);
        } catch (error) {
            document.querySelector('[data-verification-error]').textContent = error.message;
            showState(verificationError, 'verify');
        } finally {
            setLoading(submit, false);
        }
    });

    document.querySelector('[data-back-to-code]')?.addEventListener('click', () => {
        showState(pending, 'verify');
        window.setTimeout(() => verificationForm.elements.code.focus(), 50);
    });

    const params = new URLSearchParams(location.search);
    const requestedSignup = params.get('signup');
    const socialError = params.get('socialError');
    if (requestedSignup === 'complete' && location.hash.startsWith('#nexa-onboarding=')) {
        try {
            const encoded = location.hash.slice('#nexa-onboarding='.length).replace(/-/g, '+').replace(/_/g, '/');
            attemptToken = atob(encoded.padEnd(Math.ceil(encoded.length / 4) * 4, '='));
            showProfile('social', attemptToken, params.get('plan') || 'growth');
            history.replaceState(null, '', applicationUrl(`?signup=complete&plan=${encodeURIComponent(selectedPlan)}`));
        } catch {
            openDialog(params.get('plan') || 'growth');
            methodMessage.textContent = 'Your social signup session could not be restored. Please try again.';
            methodMessage.hidden = false;
        }
    } else if (plans.some(plan => plan.key === requestedSignup)) {
        openDialog(requestedSignup);

        if (socialError) {
            methodMessage.textContent = socialError === 'provider_cancelled'
                ? 'Social signup was cancelled. Please try again.'
                : 'Social signup could not be completed. Please try again.';
            methodMessage.hidden = false;
        }
    }
})();
