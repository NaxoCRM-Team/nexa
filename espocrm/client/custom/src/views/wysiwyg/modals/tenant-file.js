define('custom:views/wysiwyg/modals/tenant-file', ['views/modal'], Dep => class extends Dep {
    className = 'dialog nexa-tenant-image-dialog nexa-tenant-file-dialog';
    template = 'custom:wysiwyg/modals/tenant-file';
    noFullHeight = true;

    setup() {
        this.headerText = 'Attach file';
        this.buttonList = [];
        this.limit = 12;
        this.offset = 0;
        this.search = '';
    }

    afterRender() {
        super.afterRender();
        const search = this.element.querySelector('[data-nexa-file-search]');
        let timer;
        search.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => {
                this.search = search.value.trim();
                this.offset = 0;
                this.loadPage();
            }, 180);
        });
        this.element.querySelector('[data-nexa-file-upload]').addEventListener('change', event => {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (!file) return;
            const isImage = /^image\//i.test(file.type) || /\.(?:png|jpe?g|gif|webp)$/i.test(file.name);
            const maxSize = isImage ? 8 * 1024 * 1024 : 25 * 1024 * 1024;
            if (file.size > maxSize) {
                this.showError(isImage ? 'Images cannot be larger than 8 MB.' : 'Files cannot be larger than 25 MB.');
                return;
            }
            if (!/\.(?:pdf|docx?|xlsx?|pptx?|csv|txt|rtf|zip|png|jpe?g|gif|webp)$/i.test(file.name)) {
                this.showError('Choose a supported image, PDF, Office, CSV, TXT, RTF or ZIP file.');
                return;
            }
            this.trigger('upload', file);
            this.close();
        });
        this.element.querySelector('[data-nexa-file-previous]').addEventListener('click', () => {
            this.offset = Math.max(0, this.offset - this.limit);
            this.loadPage();
        });
        this.element.querySelector('[data-nexa-file-next]').addEventListener('click', () => {
            this.offset += this.limit;
            this.loadPage();
        });
        this.element.querySelector('[data-nexa-file-list]').addEventListener('click', event => {
            const button = event.target.closest('[data-nexa-file-id]');
            if (!button) return;
            this.trigger('insert', {id: button.dataset.nexaFileId, name: button.dataset.nexaFileName, mimeType: button.dataset.nexaFileType});
            this.close();
        });
        this.loadPage();
    }

    async loadPage() {
        const list = this.element.querySelector('[data-nexa-file-list]');
        this.element.querySelector('[data-nexa-file-error]').hidden = true;
        list.innerHTML = '<div class="nexa-image-library-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading files</span></div>';
        try {
            this.renderPage(await Espo.Ajax.getRequest('Nexa/files', {search: this.search, offset: this.offset, limit: this.limit}));
        } catch (error) {
            list.innerHTML = '';
            this.showError('The tenant file library could not be loaded.');
        }
    }

    renderPage(response) {
        const files = Array.isArray(response?.list) ? response.list : [];
        const total = Number(response?.total) || 0;
        this.element.querySelector('[data-nexa-file-list]').innerHTML = files.length ? files.map(file => `
            <button type="button" class="nexa-file-library-item" data-nexa-file-id="${this.escape(file.id)}" data-nexa-file-name="${this.escape(file.name)}" data-nexa-file-type="${this.escape(file.mimeType)}">
                <span class="nexa-file-library-icon fas ${this.icon(file)}" aria-hidden="true"></span>
                <span><strong>${this.escape(file.name)}</strong><small>${this.escape(this.typeLabel(file.mimeType))} &middot; ${this.formatSize(file.size)}</small></span>
                <span class="fas fa-plus" aria-hidden="true"></span>
            </button>`).join('') : '<div class="nexa-image-library-empty"><span class="far fa-folder-open" aria-hidden="true"></span><strong>No files found</strong></div>';
        this.element.querySelector('[data-nexa-file-count]').textContent = total === 1 ? '1 file' : `${total} files`;
        this.element.querySelector('[data-nexa-file-previous]').disabled = this.offset === 0;
        this.element.querySelector('[data-nexa-file-next]').disabled = this.offset + this.limit >= total;
        this.element.querySelector('[data-nexa-file-page]').textContent = total ? `${Math.floor(this.offset / this.limit) + 1} of ${Math.ceil(total / this.limit)}` : '0 of 0';
    }

    icon(file) {
        const type = String(file.mimeType || '');
        if (type.includes('pdf')) return 'fa-file-pdf';
        if (type.includes('word') || /\.docx?$/i.test(file.name)) return 'fa-file-word';
        if (type.includes('sheet') || type.includes('excel') || /\.(?:xlsx?|csv)$/i.test(file.name)) return 'fa-file-excel';
        if (type.startsWith('image/')) return 'fa-file-image';
        if (type.includes('zip')) return 'fa-file-archive';
        return 'fa-file-alt';
    }

    typeLabel(value) {
        return String(value || 'File').split('/').pop().replace(/^vnd\./, '').replace(/\./g, ' ').toUpperCase();
    }

    showError(message) {
        const error = this.element.querySelector('[data-nexa-file-error]');
        error.textContent = message;
        error.hidden = false;
    }

    formatSize(value) {
        const bytes = Number(value) || 0;
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${Math.round(bytes / 102.4) / 10} KB`;
        return `${Math.round(bytes / 1024 / 102.4) / 10} MB`;
    }

    escape(value) {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    }
});
