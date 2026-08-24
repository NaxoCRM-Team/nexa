define('custom:views/wysiwyg/modals/tenant-image', ['views/modal'], Dep => class extends Dep {
    className = 'dialog nexa-tenant-image-dialog';
    template = 'custom:wysiwyg/modals/tenant-image';
    noFullHeight = true;

    setup() {
        this.headerText = 'Insert image';
        this.buttonList = [];
        this.limit = 12;
        this.offset = 0;
        this.search = '';
        this.previewUrls = [];
    }

    afterRender() {
        super.afterRender();
        const search = this.element.querySelector('[data-nexa-image-search]');
        const upload = this.element.querySelector('[data-nexa-image-upload]');
        let timer;

        search?.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => {
                this.search = search.value.trim();
                this.offset = 0;
                this.loadPage();
            }, 180);
        });
        upload?.addEventListener('change', event => {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (!file) return;
            if (!/^image\/(png|jpeg|gif|webp)$/i.test(file.type) || file.size > 8 * 1024 * 1024) {
                this.showError('Choose a PNG, JPG, GIF or WebP image no larger than 8 MB.');
                return;
            }
            this.trigger('upload', file);
            this.close();
        });
        this.element.querySelector('[data-nexa-image-previous]')?.addEventListener('click', () => {
            this.offset = Math.max(0, this.offset - this.limit);
            this.loadPage();
        });
        this.element.querySelector('[data-nexa-image-next]')?.addEventListener('click', () => {
            this.offset += this.limit;
            this.loadPage();
        });
        this.element.querySelector('[data-nexa-image-grid]')?.addEventListener('click', event => {
            const button = event.target.closest('[data-nexa-image-id]');
            if (!button) return;
            this.trigger('insert', {id: button.dataset.nexaImageId, name: button.dataset.nexaImageName});
            this.close();
        });
        this.loadPage();
    }

    async loadPage() {
        const grid = this.element.querySelector('[data-nexa-image-grid]');
        const error = this.element.querySelector('[data-nexa-image-error]');
        error.hidden = true;
        grid.innerHTML = '<div class="nexa-image-library-loading"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span><span>Loading images</span></div>';
        this.releasePreviewUrls();
        try {
            const response = await Espo.Ajax.getRequest('Nexa/files/images', {
                search: this.search,
                offset: this.offset,
                limit: this.limit,
            });
            this.renderPage(response);
        } catch (requestError) {
            grid.innerHTML = '';
            this.showError('The tenant image library could not be loaded.');
        }
    }

    renderPage(response) {
        const list = Array.isArray(response?.list) ? response.list : [];
        const total = Number(response?.total) || 0;
        const grid = this.element.querySelector('[data-nexa-image-grid]');
        grid.innerHTML = list.length ? list.map(item => `
            <button type="button" class="nexa-image-library-item" data-nexa-image-id="${this.escape(item.id)}" data-nexa-image-name="${this.escape(item.name)}">
                <span class="nexa-image-library-preview" data-nexa-image-preview="${this.escape(item.id)}"><span class="far fa-image" aria-hidden="true"></span></span>
                <strong title="${this.escape(item.name)}">${this.escape(item.name)}</strong>
                <small>${this.formatSize(item.size)}</small>
            </button>`).join('') : '<div class="nexa-image-library-empty"><span class="far fa-images" aria-hidden="true"></span><strong>No images found</strong></div>';
        this.element.querySelector('[data-nexa-image-count]').textContent = total === 1 ? '1 image' : `${total} images`;
        const previous = this.element.querySelector('[data-nexa-image-previous]');
        const next = this.element.querySelector('[data-nexa-image-next]');
        previous.disabled = this.offset === 0;
        next.disabled = this.offset + this.limit >= total;
        this.element.querySelector('[data-nexa-image-page]').textContent = total ? `${Math.floor(this.offset / this.limit) + 1} of ${Math.ceil(total / this.limit)}` : '0 of 0';
        list.forEach(item => this.loadPreview(item));
    }

    async loadPreview(item) {
        const host = this.element.querySelector(`[data-nexa-image-preview="${CSS.escape(item.id)}"]`);
        if (!host) return;
        try {
            const response = await Espo.Ajax.getRequest(`Nexa/attachment-file/${encodeURIComponent(item.id)}`);
            if (!host.isConnected || !response?.data) return;
            const binary = atob(response.data);
            const bytes = new Uint8Array(binary.length);
            for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
            const url = URL.createObjectURL(new Blob([bytes], {type: response.mimeType || item.mimeType}));
            this.previewUrls.push(url);
            host.innerHTML = `<img src="${url}" alt="">`;
        } catch (error) {
            host.classList.add('is-unavailable');
        }
    }

    showError(message) {
        const error = this.element.querySelector('[data-nexa-image-error]');
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

    releasePreviewUrls() {
        this.previewUrls.forEach(url => URL.revokeObjectURL(url));
        this.previewUrls = [];
    }

    remove() {
        this.releasePreviewUrls();
        return super.remove();
    }
});
