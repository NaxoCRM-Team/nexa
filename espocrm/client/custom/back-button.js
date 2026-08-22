// There is currently no in-app way to return to the page you came from
// (e.g. Contact list -> a Contact, or a Contact -> a Case/Opportunity/
// Document opened from it) short of the browser's own back button, which
// isn't discoverable as part of the app UI. views/header is the single
// shared breadcrumb component every entity's detail page renders through
// (confirmed: Nexa's own heavily-customized Contact workspace doesn't
// override getHeader() or touch .page-header/.header-title at all, so it
// goes through this exact same native path) - adding the button here
// covers every entity uniformly instead of once per page.
require(['views/header'], HeaderView => {
    const originalAfterRender = HeaderView.prototype.afterRender;

    HeaderView.prototype.afterRender = function (...args) {
        const result = originalAfterRender.apply(this, args);

        // Only record ("view") pages have somewhere meaningful to go back
        // to in this flow - list pages are already the top of a section.
        // This app routes via pushState (e.g. /w/{tenant}/Contact/view/{id})
        // rather than a URL hash, so the path itself is what's checked.
        const isDetailPage = /\/[A-Za-z0-9]+\/view\/[A-Za-z0-9]+/.test(window.location.pathname);
        const titleColumn = this.element?.querySelector('.page-header-column-1');

        if (isDetailPage && titleColumn && !titleColumn.querySelector('[data-nexa-back-button]')) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'nexa-back-button';
            button.dataset.nexaBackButton = 'true';
            button.setAttribute('aria-label', 'Back to previous page');
            button.title = 'Back';
            button.innerHTML = '<span class="fas fa-arrow-left" aria-hidden="true"></span>';
            button.addEventListener('click', () => window.history.back());
            titleColumn.prepend(button);
        }

        return result;
    };
});
