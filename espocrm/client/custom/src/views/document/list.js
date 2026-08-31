define('custom:views/document/list', ['crm:views/document/list'], Dep => class extends Dep {
    setup() {
        super.setup();
        this.once('remove', () => {
            this.controlsObserver?.disconnect();
            this.element?.classList.remove('nexa-document-list-page');
        });
    }

    afterRender() {
        const result = super.afterRender();
        this.element?.classList.add('nexa-document-list-page');
        this.decorateControls();
        this.controlsObserver = new MutationObserver(() => this.decorateControls());
        this.controlsObserver.observe(this.element, {childList: true, subtree: true});
        return result;
    }

    decorateControls() {
        const create = this.element?.querySelector('.page-header .btn[data-action="create"], .page-header .btn');
        if (create && !create.classList.contains('nexa-document-create-button')) {
            create.classList.add('nexa-document-create-button');
            create.innerHTML = '<span class="fas fa-plus" aria-hidden="true"></span><span>New Document</span>';
        }

        const header = this.element?.querySelector('.page-header');
        if (header && !this.element.querySelector('.nexa-document-links')) {
            header.insertAdjacentHTML('afterend', `<nav class="nexa-native-workspace-links nexa-document-links" aria-label="Document navigation">
                <a href="#NexaActivity/projects"><span class="fas fa-folder-open" aria-hidden="true"></span>Projects</a>
                <a href="#NexaActivity/agenda"><span class="fas fa-list-check" aria-hidden="true"></span>Agenda</a>
            </nav>`);
        }
    }
});
