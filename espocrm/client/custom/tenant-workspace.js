require(['views/site/navbar'], NavbarView => {
    const defaultData = NavbarView.prototype.data;
    const defaultAfterRender = NavbarView.prototype.afterRender;
    const plannedModuleGroups = [
        {
            name: 'nexa-marketing-suite',
            label: 'Marketing Suite',
            iconClass: 'fas fa-bullhorn',
            modules: [
                ['nexa-forms-content', 'Consent, Forms & Content'],
                ['nexa-marketing-contacts', 'Marketing Contacts & Events'],
                ['nexa-marketing-email', 'Marketing Email'],
                ['nexa-tracking-events', 'Tracking & Events'],
                ['nexa-automation', 'Automation'],
                ['nexa-scoring-abm', 'Scoring, Personalization & ABM'],
                ['nexa-experiments', 'Experiments'],
            ],
        },
        {
            name: 'nexa-customer-engagement',
            label: 'Customer Engagement',
            iconClass: 'fas fa-comments',
            modules: [
                ['nexa-conversations', 'Conversations & Bots'],
                ['nexa-messaging', 'SMS & WhatsApp'],
                ['nexa-social', 'Social Workspace'],
                ['nexa-advertising', 'Advertising'],
            ],
        },
        {
            name: 'nexa-intelligence',
            label: 'Intelligence',
            iconClass: 'fas fa-chart-line',
            modules: [
                ['nexa-seo-content', 'SEO & Content Intelligence'],
                ['nexa-analytics', 'Analytics & Attribution'],
                ['nexa-ai-services', 'AI Services'],
            ],
        },
        {
            name: 'nexa-platform',
            label: 'Platform',
            iconClass: 'fas fa-layer-group',
            modules: [
                ['nexa-saas-admin', 'SaaS Administration'],
                ['nexa-access-security', 'Access & Security'],
                ['nexa-integrations', 'Enterprise Integrations'],
                ['nexa-support-operations', 'Support Operations'],
            ],
        },
    ];

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

    const createPlannedGroup = group => ({
        name: group.name,
        label: group.label,
        shortLabel: group.label.substring(0, 2),
        link: null,
        iconClass: group.iconClass,
        isGroup: true,
        isDivider: false,
        isInMore: false,
        isAfterShowMore: false,
        aClassName: 'nav-link-group nexa-planned-group-link',
        itemList: group.modules.map(createPlannedModule),
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

    NavbarView.prototype.data = function () {
        const data = defaultData.call(this);

        if (!this.isSide()) {
            return data;
        }

        const existingTabs = [...data.tabDefsList1, ...data.tabDefsList2]
            .filter(item => item.name !== 'show-more')
            .map(item => ({
                ...item,
                isInMore: false,
                isAfterShowMore: false,
            }));
        const nexaDivider = {
            name: 'nexa-modules-divider',
            label: 'Nexa Modules',
            isDivider: true,
            isGroup: false,
            isInMore: false,
            isAfterShowMore: false,
            aClassName: 'nav-divider-text',
        };

        data.tabDefsList1 = [
            ...existingTabs,
            nexaDivider,
            ...plannedModuleGroups.map(createPlannedGroup),
        ];
        data.tabDefsList2 = [];

        return data;
    };

    NavbarView.prototype.afterRender = function () {
        const result = defaultAfterRender.call(this);

        try {
            document.body.classList.toggle('nexa-side-navigation', this.isSide());
            enhanceHeaderControls(this);

            const navigation = this.element?.querySelector('.navbar-body');
            const toggle = this.element?.querySelector('.navbar-toggle');
            const main = document.querySelector('#main');

            this.element?.setAttribute('aria-label', 'Primary application navigation');
            navigation?.setAttribute('aria-label', 'Workspace modules');
            main?.setAttribute('role', 'main');

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
