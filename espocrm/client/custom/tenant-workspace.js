require(['views/site/navbar', 'custom:product-surface-registry'], (NavbarView, productSurfaceRegistry) => {
    const defaultData = NavbarView.prototype.data;
    const defaultAfterRender = NavbarView.prototype.afterRender;
    const updateCurrentYear = () => {
        const year = String(new Date().getFullYear());
        document.querySelectorAll('[data-nexa-current-year]').forEach(element => {
            if (element.textContent !== year) element.textContent = year;
        });
        document.querySelectorAll('body > footer .credit').forEach(footer => {
            const label = `© Nexa CRM ${year}`;
            if (footer.textContent.trim() !== label) footer.textContent = label;
        });
    };
    const footerObserver = new MutationObserver(updateCurrentYear);
    footerObserver.observe(document.body, {childList: true, subtree: true});
    // Keep navigation organized around daily workspaces. Existing Espo screens are
    // reused by scope name; missing Nexa screens remain visibly disabled until their
    // routes and permission contracts are delivered.
    const legacyWorkspaceNavigation = [
        {
            name: 'nexa-crm',
            label: 'CRM',
            iconClass: 'fas fa-address-book',
            items: [
                'Account',
                'Contact',
                'Lead',
                ['nexa-customer-360', 'Customer 360'],
                ['nexa-customer-timeline', 'Customer Timeline'],
                ['nexa-lists-segments', 'Lists & Segments'],
                ['nexa-lifecycle', 'Lifecycle'],
                ['nexa-custom-objects', 'Custom Objects'],
            ],
        },
        {
            name: 'nexa-sales',
            label: 'Sales',
            iconClass: 'fas fa-chart-line',
            items: [
                'Opportunity',
                'Meeting',
                'Call',
                'Task',
                'Calendar',
                ['nexa-sales-pipelines', 'Pipelines'],
                ['nexa-sales-forecasts', 'Forecasts'],
                ['nexa-products-quotes', 'Products & Quotes'],
                'Document',
                'Template',
            ],
        },
        {
            name: 'nexa-marketing',
            label: 'Marketing',
            iconClass: 'fas fa-bullhorn',
            items: [
                'Campaign',
                'TargetList',
                'EmailTemplate',
                ['nexa-marketing-contacts', 'Marketing Contacts'],
                ['nexa-marketing-email', 'Marketing Email'],
                ['nexa-forms', 'Forms'],
                ['nexa-landing-pages', 'Landing Pages'],
                ['nexa-content-assets', 'Content & Assets'],
                ['nexa-marketing-events', 'Marketing Events'],
                ['nexa-experiments', 'Experiments'],
            ],
        },
        {
            name: 'nexa-automation',
            label: 'Automation',
            iconClass: 'fas fa-project-diagram',
            items: [
                ['nexa-workflows', 'Workflows'],
                ['nexa-customer-journeys', 'Customer Journeys'],
                ['nexa-scoring-lifecycle', 'Scoring & Lifecycle'],
                ['nexa-personalization', 'Personalization'],
                ['nexa-target-accounts', 'Target Accounts & ABM'],
            ],
        },
        {
            name: 'nexa-service',
            label: 'Service',
            iconClass: 'fas fa-comments',
            items: [
                'Case',
                'Email',
                'KnowledgeBaseArticle',
                ['nexa-shared-inbox', 'Shared Inbox'],
                ['nexa-service-queues', 'Queues & SLAs'],
                ['nexa-customer-portal', 'Customer Portal'],
            ],
        },
        {
            name: 'nexa-channels',
            label: 'Channels',
            iconClass: 'fas fa-broadcast-tower',
            items: [
                ['nexa-live-chat', 'Live Chat'],
                ['nexa-conversational-bots', 'Bots'],
                ['nexa-sms', 'SMS'],
                ['nexa-whatsapp', 'WhatsApp'],
                ['nexa-social', 'Social Media'],
                ['nexa-advertising', 'Advertising'],
            ],
        },
        {
            name: 'nexa-analytics',
            label: 'Analytics',
            iconClass: 'fas fa-chart-bar',
            items: [
                ['nexa-analytics-dashboards', 'Dashboards'],
                ['nexa-reports', 'Reports'],
                ['nexa-campaign-analytics', 'Campaign Analytics'],
                ['nexa-attribution', 'Attribution'],
                ['nexa-journey-analytics', 'Journey Analytics'],
                ['nexa-website-traffic', 'Website Traffic'],
                ['nexa-email-performance', 'Email Performance'],
                ['nexa-sales-analytics', 'Sales Analytics'],
                ['nexa-service-analytics', 'Service Analytics'],
                ['nexa-customer-analytics', 'Customer Analytics'],
                ['nexa-seo-analytics', 'SEO Analytics'],
            ],
        },
        {
            name: 'nexa-data-tools',
            label: 'Data & Integrations',
            iconClass: 'fas fa-database',
            items: [
                ['nexa-tracking-events', 'Tracking & Events'],
                ['nexa-data-quality', 'Data Quality'],
                ['nexa-consent-privacy', 'Consent & Privacy'],
                ['nexa-import-export', 'Import & Export'],
                ['nexa-integrations', 'Integrations'],
                ['nexa-api-webhooks', 'API & Webhooks'],
            ],
        },
    ];
    const workspaceNavigation = productSurfaceRegistry.navigation || legacyWorkspaceNavigation;

    const createPlannedModule = ([name, label]) => ({
        name,
        label,
        shortLabel: label.substring(0, 2),
        link: null,
        isGroup: false,
        isDivider: false,
        isInMore: false,
        isAfterShowMore: false,
        aClassName: 'nexa-planned-module-link',
    });

    const createWorkspaceGroup = (group, existingByName, usedNames) => ({
        name: group.name,
        label: group.label,
        shortLabel: group.label.substring(0, 2),
        link: null,
        iconClass: group.iconClass,
        isGroup: true,
        isDivider: false,
        isInMore: false,
        isAfterShowMore: false,
        aClassName: 'nav-link-group nexa-workspace-group-link',
        itemList: group.items.map(item => {
            if (typeof item !== 'string') {
                return createPlannedModule(item);
            }

            const existing = existingByName.get(item);

            if (!existing) {
                return createPlannedModule([`nexa-${item.toLowerCase()}`, item]);
            }

            usedNames.add(item);

            return {
                ...existing,
                isInMore: false,
                isAfterShowMore: false,
            };
        }),
    });
    const createTenantIdentity = (tenant, className) => {
        const displayName = tenant.displayName || tenant.slug;
        const initials = displayName
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map(part => part.charAt(0).toUpperCase())
            .join('');
        const identity = document.createElement(className.includes('nexa-header-tenant') ? 'li' : 'div');
        const mark = document.createElement('span');
        const copy = document.createElement('span');
        const label = document.createElement('span');
        const name = document.createElement('strong');

        identity.className = className;
        identity.dataset.tenantId = tenant.id;
        identity.title = `Current workspace: ${displayName}`;
        identity.setAttribute('aria-label', identity.title);
        if (identity.tagName === 'DIV') identity.setAttribute('role', 'group');
        mark.className = 'nexa-tenant-mark';
        mark.textContent = initials || 'N';
        copy.className = 'nexa-tenant-copy';
        label.className = 'nexa-tenant-label';
        label.textContent = 'Workspace';
        name.className = 'nexa-tenant-name';
        name.textContent = displayName;
        copy.append(label, name);
        identity.append(mark, copy);

        return identity;
    };

    const enhanceHeaderControls = view => {
        const root = view.element;
        const navbar = root?.querySelector('.navbar');
        const brand = root?.querySelector('.navbar-brand');

        navbar?.classList.add('nexa-premium-header');

        if (brand && !brand.querySelector('.nexa-brand-lockup')) {
            const originalLogo = brand.querySelector('img.logo');
            const lockup = document.createElement('span');
            const mark = document.createElement('span');
            const copy = document.createElement('span');
            const product = document.createElement('strong');
            const category = document.createElement('small');

            brand.setAttribute('aria-label', 'Nexa CRM home');
            brand.title = 'Nexa CRM';
            originalLogo?.setAttribute('aria-hidden', 'true');
            lockup.className = 'nexa-brand-lockup';
            mark.className = 'nexa-brand-mark';
            mark.textContent = 'N';
            copy.className = 'nexa-brand-copy';
            product.textContent = 'Nexa';
            category.textContent = 'CRM';
            copy.append(product, category);
            lockup.append(mark, copy);
            brand.append(lockup);
        }

        const searchInput = root?.querySelector('.global-search-input');
        const searchButton = root?.querySelector('.global-search-button');
        const quickCreate = root?.querySelector('#nav-quick-create-dropdown');
        const notifications = root?.querySelector('.notifications-button');
        const menu = root?.querySelector('#nav-menu-dropdown');

        if (searchInput) {
            searchInput.placeholder = 'Search customers, deals and more';
            searchInput.setAttribute('aria-label', 'Search across this workspace');
            searchInput.setAttribute('autocomplete', 'off');
        }
        if (searchButton) {
            searchButton.setAttribute('aria-label', 'Search workspace');
            searchButton.setAttribute('role', 'button');
            searchButton.setAttribute('tabindex', '0');
        }
        quickCreate?.setAttribute('aria-label', 'Create a new record');
        notifications?.setAttribute('aria-label', 'Open notifications');

        if (menu && !menu.querySelector('.nexa-profile-avatar')) {
            const user = view.getUser();
            const displayName = user.get('name') || user.get('userName') || 'Account';
            const initials = displayName
                .split(/\s+/)
                .filter(Boolean)
                .slice(0, 2)
                .map(part => part.charAt(0).toUpperCase())
                .join('');
            const avatar = document.createElement('span');
            const name = document.createElement('span');
            const caret = document.createElement('span');

            menu.replaceChildren();
            menu.setAttribute('aria-label', `Open account menu for ${displayName}`);
            menu.title = 'Account menu';
            avatar.className = 'nexa-profile-avatar';
            avatar.textContent = initials || 'U';
            name.className = 'nexa-profile-name';
            name.textContent = displayName;
            caret.className = 'fas fa-chevron-down nexa-profile-caret';
            caret.setAttribute('aria-hidden', 'true');
            menu.append(avatar, name, caret);
        }
    };

    const enhanceWorkspaceNavigation = navigation => {
        navigation.setAttribute('role', 'navigation');

        navigation.querySelectorAll('.nexa-workspace-group-link').forEach((link, index) => {
            const item = link.closest('li');
            const menu = item?.querySelector('.dropdown-menu');

            if (!menu) return;
            menu.id ||= `nexa-workspace-group-${index}`;
            link.setAttribute('aria-controls', menu.id);
            link.setAttribute('aria-haspopup', 'true');
            link.setAttribute('aria-expanded', String(item.classList.contains('open')));
            link.addEventListener('click', () => window.setTimeout(() => {
                link.setAttribute('aria-expanded', String(item.classList.contains('open')));
            }, 0));
        });

        if (navigation.dataset.nexaKeyboardReady === 'true') return;
        navigation.dataset.nexaKeyboardReady = 'true';
        navigation.addEventListener('keydown', event => {
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;

            const links = [...navigation.querySelectorAll('a, button')]
                .filter(item => item.getAttribute('aria-disabled') !== 'true' &&
                    !item.hasAttribute('disabled') && item.offsetParent !== null);
            const currentIndex = links.indexOf(document.activeElement);

            if (currentIndex < 0 || links.length === 0) return;
            event.preventDefault();
            let nextIndex = currentIndex;
            if (event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % links.length;
            if (event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + links.length) % links.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = links.length - 1;
            links[nextIndex].focus();
        });
    };

    NavbarView.prototype.data = function () {
        const data = defaultData.call(this);

        if (!this.isSide()) {
            return data;
        }

        const existingTabs = [...data.tabDefsList1, ...data.tabDefsList2]
            .filter(item => item.name !== 'show-more' && !item.isDivider)
            .map(item => ({
                ...item,
                isInMore: false,
                isAfterShowMore: false,
            }));
        const existingByName = new Map(existingTabs.map(item => [item.name, item]));
        const usedNames = new Set();
        const home = existingByName.get('Home');
        const workspaceDivider = {
            name: 'nexa-workspaces-divider',
            label: 'Workspaces',
            isDivider: true,
            isGroup: false,
            isInMore: false,
            isAfterShowMore: false,
            aClassName: 'nav-divider-text',
        };

        if (home) usedNames.add('Home');

        const workspaceGroups = workspaceNavigation.map(group =>
            createWorkspaceGroup(group, existingByName, usedNames));
        const administrationScopes = new Set([
            'User',
            'Team',
            'WorkingTimeCalendar',
            'Import',
        ]);
        const additionalTabs = existingTabs.filter(item =>
            !usedNames.has(item.name) && !administrationScopes.has(item.name));

        data.tabDefsList1 = [
            ...(home ? [home] : []),
            workspaceDivider,
            ...workspaceGroups,
            ...additionalTabs,
        ];
        data.tabDefsList2 = [];

        return data;
    };

    NavbarView.prototype.afterRender = function () {
        const result = defaultAfterRender.call(this);

        try {
            updateCurrentYear();
            document.body.classList.toggle('nexa-side-navigation', this.isSide());
            enhanceHeaderControls(this);

            const navigation = this.element?.querySelector('.navbar-body');
            const toggle = this.element?.querySelector('.navbar-toggle');
            const main = document.querySelector('#main');

            this.element?.setAttribute('aria-label', 'Primary application navigation');
            navigation?.setAttribute('aria-label', 'Workspace modules');
            main?.setAttribute('role', 'main');

            if (navigation) enhanceWorkspaceNavigation(navigation);

            if (toggle && navigation) {
                toggle.setAttribute('aria-label', 'Open workspace navigation');
                toggle.setAttribute('aria-controls', navigation.id || 'nexa-workspace-navigation');
                navigation.id ||= 'nexa-workspace-navigation';

                toggle.addEventListener('click', () => window.setTimeout(() => {
                    const isOpen = navigation.classList.contains('in');
                    toggle.setAttribute('aria-expanded', String(isOpen));
                    toggle.setAttribute('aria-label', `${isOpen ? 'Close' : 'Open'} workspace navigation`);
                    if (isOpen) {
                        navigation.querySelector('a:not([aria-disabled="true"])')?.focus();
                    }
                }, 0));

                navigation.addEventListener('keydown', event => {
                    if (event.key === 'Escape' && navigation.classList.contains('in')) {
                        event.preventDefault();
                        toggle.click();
                        toggle.focus();
                    }
                });
            }

            this.element
                ?.querySelectorAll('.nexa-planned-module-link')
                .forEach(link => {
                    const label = link.querySelector('.full-label')?.textContent?.trim() || 'Module';

                    link.setAttribute('aria-disabled', 'true');
                    link.setAttribute('aria-label', `${label}, planned module`);
                    link.title = 'Planned module';

                    const indicator = document.createElement('span');
                    indicator.className = 'fas fa-clock nexa-planned-module-indicator';
                    indicator.setAttribute('aria-hidden', 'true');
                    link.append(indicator);
                    link.addEventListener('click', event => {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                    });
                });

            const tenant = this.getHelper().getAppParam('nexaTenant');
            const container = this.element?.querySelector('.navbar-right-container');
            const rightList = container?.querySelector('.navbar-right');
            const mobileHeader = this.element?.querySelector('.navbar-header');

            if (!tenant || !container || !rightList || !mobileHeader) {
                return result;
            }

            this.element.querySelectorAll('.nexa-tenant-identity').forEach(element => element.remove());
            rightList.prepend(createTenantIdentity(tenant, 'nexa-tenant-identity nexa-header-tenant'));
            mobileHeader.append(createTenantIdentity(tenant, 'nexa-tenant-identity nexa-mobile-tenant'));
            document.body.dataset.tenantSlug = tenant.slug;
        } catch (error) {
            console.warn('Unable to enhance the tenant workspace navigation.', error);
        }

        return result;
    };
});
