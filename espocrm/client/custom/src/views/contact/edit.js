define('custom:views/contact/edit', ['views/edit'], Dep => {
    /**
     * Gives Contact creation a task-specific heading while preserving Espo's
     * standard breadcrumb, routing, record view and keyboard behavior.
     */
    return class extends Dep {
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
