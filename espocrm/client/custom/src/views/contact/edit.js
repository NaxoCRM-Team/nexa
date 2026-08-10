define('custom:views/contact/edit', ['views/edit'], Dep => {
    /**
     * Gives Contact creation a task-specific heading while preserving Espo's
     * standard breadcrumb, routing, record view and keyboard behavior.
     */
    return class extends Dep {
        afterRender() {
            super.afterRender();

            if (!this.model.isNew() || this.$el.find('.nexa-contact-import-prompt').length) {
                return;
            }

            const prompt = document.createElement('aside');
            prompt.className = 'nexa-contact-import-prompt';
            prompt.innerHTML = '<span class="fas fa-info-circle" aria-hidden="true"></span>' +
                '<div><strong>Want to add more than one contact at once?</strong>' +
                '<span>Save time and use the contact import tool.</span></div>' +
                '<button type="button" class="btn btn-link">Import multiple contacts</button>';
            prompt.querySelector('button').addEventListener('click', () => {
                this.getRouter().navigate('#Contact/import', {trigger: true});
            });

            this.$el.find('.record').before(prompt);
        }

        getHeader() {
            if (!this.model.isNew()) {
                return super.getHeader();
            }

            const scopeLabel = this.getLanguage().translate(this.scope, 'scopeNamesPlural');
            const link = document.createElement('a');
            link.href = this.rootUrl;
            link.classList.add('action');
            link.dataset.action = 'navigateToRoot';
            link.textContent = scopeLabel;

            const root = document.createElement('span');
            root.style.userSelect = 'none';
            root.append(link);

            const iconHtml = this.getHeaderIconHtml();
            if (iconHtml) {
                root.insertAdjacentHTML('afterbegin', iconHtml);
            }

            const title = document.createElement('span');
            title.textContent = this.translate('New Contact', 'labels', 'Contact');
            title.style.userSelect = 'none';

            return this.buildHeaderHtml([root, title]);
        }

        updatePageTitle() {
            if (!this.model.isNew()) {
                super.updatePageTitle();
                return;
            }

            this.setPageTitle(this.translate('New Contact', 'labels', 'Contact'));
        }
    };
});
