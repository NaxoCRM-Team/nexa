define('custom:views/fields/nexa-rich-text', ['views/fields/wysiwyg', 'custom:helpers/tenant-images', 'custom:helpers/tenant-files'], (Dep, TenantImages, TenantFiles) => class extends Dep {
    setup() {
        super.setup();
        this.once('remove', () => this.removeImageResizeHandle());
    }

    setupToolbar() {
        super.setupToolbar();
        let hasFileButton = false;
        this.toolbar = this.toolbar.map(([group, buttons]) => [
            group,
            buttons.map(name => {
                if (name === 'attachment') {
                    hasFileButton = true;
                    return 'nexaTenantFile';
                }
                return name === 'espoImage' ? 'nexaTenantImage' : name;
            }),
        ]);
        if (!hasFileButton) this.toolbar.push(['attachment', ['nexaTenantFile']]);
        this.buttons.nexaTenantImage = () => $.summernote.ui.button({
            contents: '<span class="far fa-image" aria-hidden="true"></span>',
            tooltip: 'Insert image',
            click: () => this.openTenantImageLibrary(),
        }).render();
        this.buttons.nexaTenantFile = () => $.summernote.ui.button({
            contents: '<span class="fas fa-paperclip" aria-hidden="true"></span>',
            tooltip: 'Attach file',
            click: () => this.openTenantFileLibrary(),
        }).render();
    }

    afterRender() {
        super.afterRender();
        if (!this.isEditMode() || !this.$summernote) return;
        TenantFiles.install();
        const editable = this.$el.find('.note-editable').get(0);
        if (!editable) return;
        TenantImages.hydrate(editable).then(() => this.bindResizableImages());
        this.imageEditorClickHandler = event => {
            const image = event.target.closest('img[data-nexa-attachment-id]');
            if (image && editable.contains(image)) this.selectResizableImage(image);
            else if (!event.target.closest('.nexa-image-resize-handle')) this.removeImageResizeHandle(false);
        };
        editable.addEventListener('click', this.imageEditorClickHandler);
    }

    openTenantFileLibrary() {
        this.$summernote.summernote('saveRange');
        this.clearView('nexaTenantFileDialog');
        this.createView('nexaTenantFileDialog', 'custom:views/wysiwyg/modals/tenant-file', {}, view => {
            view.render();
            this.listenToOnce(view, 'insert', attachment => this.insertTenantFile(attachment));
            this.listenToOnce(view, 'upload', file => {
                Espo.Ui.notify('Uploading...');
                this.uploadTenantFile(file).then(attachment => this.insertTenantFile(attachment).then(() => {
                    Espo.Ui.notify(false);
                    Espo.Ui.success('File attached');
                })).catch(error => {
                    Espo.Ui.notify(false);
                    Espo.Ui.error(error?.message || 'The file could not be uploaded.');
                });
            });
            this.listenToOnce(view, 'close', () => {
                this.clearView('nexaTenantFileDialog');
                this.fixPopovers();
            });
        });
    }

    async insertTenantFile(attachment) {
        this.$summernote.summernote('restoreRange');
        this.$summernote.summernote('focus');
        const link = document.createElement('a');
        link.href = `?entryPoint=download&id=${encodeURIComponent(attachment.id)}`;
        link.dataset.nexaFileId = attachment.id;
        link.dataset.nexaFileName = attachment.name || 'Attached file';
        link.className = 'nexa-tenant-file-link';
        link.innerHTML = `<span class="fas fa-paperclip" aria-hidden="true"></span><span>${this.escapeFileName(attachment.name || 'Attached file')}</span>`;
        this.$summernote.summernote('insertNode', link);
        this.$summernote.summernote('insertNode', document.createTextNode(' '));
        this.trigger('change');
    }

    openTenantImageLibrary() {
        this.$summernote.summernote('saveRange');
        this.clearView('nexaTenantImageDialog');
        this.createView('nexaTenantImageDialog', 'custom:views/wysiwyg/modals/tenant-image', {}, view => {
            view.render();
            this.listenToOnce(view, 'insert', attachment => this.insertTenantImage(attachment));
            this.listenToOnce(view, 'upload', file => {
                Espo.Ui.notify('Uploading...');
                this.uploadTenantImage(file).then(attachment => this.insertTenantImage(attachment).then(() => {
                    Espo.Ui.notify(false);
                    Espo.Ui.success('Image uploaded');
                })).catch(() => {
                    Espo.Ui.notify(false);
                    Espo.Ui.error('The image could not be uploaded.');
                });
            });
            this.listenToOnce(view, 'close', () => {
                this.clearView('nexaTenantImageDialog');
                this.fixPopovers();
            });
        });
    }

    async insertTenantImage(attachment) {
        const source = await TenantImages.load(attachment.id);
        this.$summernote.summernote('restoreRange');
        this.$summernote.summernote('focus');
        const image = await new Promise((resolve, reject) => {
            const timeout = window.setTimeout(() => reject(new Error('The image could not be inserted.')), 8000);
            this.$summernote.summernote('insertImage', source, inserted => {
                window.clearTimeout(timeout);
                resolve(inserted?.jquery ? inserted.get(0) : inserted);
            });
        });
        if (!(image instanceof HTMLImageElement)) throw new Error('The image could not be inserted.');
        image.dataset.nexaAttachmentId = attachment.id;
        image.dataset.nexaImageName = attachment.name || '';
        image.alt = attachment.name || 'Embedded image';
        image.classList.add('nexa-tenant-inline-image');
        const maxWidth = Math.min(image.naturalWidth || 560, this.$el.find('.note-editable').width() || 560);
        image.dataset.nexaImageWidth = String(Math.round(maxWidth));
        image.style.width = `${Math.round(maxWidth)}px`;
        image.style.height = 'auto';
        this.selectResizableImage(image);
        this.$summernote.summernote('focus');
        this.trigger('change');
    }

    bindResizableImages() {
        this.$el.find('.note-editable img[data-nexa-attachment-id]').each((index, image) => {
            image.classList.add('nexa-tenant-inline-image');
            const width = Math.round(Number.parseFloat(image.dataset.nexaImageWidth || image.style.width || image.width));
            if (width > 0) image.style.width = `${width}px`;
            image.style.height = 'auto';
        });
    }

    selectResizableImage(image) {
        this.removeImageResizeHandle(false);
        const editor = this.$el.find('.note-editor').get(0);
        if (!editor) return;
        image.classList.add('is-selected');
        const handle = document.createElement('button');
        handle.type = 'button';
        handle.className = 'nexa-image-resize-handle';
        handle.setAttribute('aria-label', 'Resize image. Drag or use arrow keys.');
        handle.innerHTML = '<span class="fas fa-arrows-alt-h" aria-hidden="true"></span>';
        editor.append(handle);
        this.selectedTenantImage = image;
        this.imageResizeHandle = handle;
        this.positionImageResizeHandle();
        this.imageResizePositionHandler = () => this.positionImageResizeHandle();
        window.addEventListener('resize', this.imageResizePositionHandler);
        document.addEventListener('scroll', this.imageResizePositionHandler, true);
        handle.addEventListener('pointerdown', event => this.startImageResize(event));
        handle.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            this.resizeSelectedImage((image.getBoundingClientRect().width || 120) + (event.key === 'ArrowRight' ? 16 : -16));
        });
    }

    positionImageResizeHandle() {
        const image = this.selectedTenantImage;
        const handle = this.imageResizeHandle;
        if (!image?.isConnected || !handle) return;
        const imageRect = image.getBoundingClientRect();
        handle.style.left = `${imageRect.right - 9}px`;
        handle.style.top = `${imageRect.bottom - 9}px`;
    }

    startImageResize(event) {
        event.preventDefault();
        const image = this.selectedTenantImage;
        if (!image) return;
        const startX = event.clientX;
        const startWidth = image.getBoundingClientRect().width;
        const pointerId = event.pointerId;
        event.currentTarget.setPointerCapture?.(pointerId);
        const move = moveEvent => {
            if (moveEvent.pointerId !== pointerId) return;
            this.resizeSelectedImage(startWidth + moveEvent.clientX - startX);
        };
        const end = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', end);
            event.currentTarget.releasePointerCapture?.(pointerId);
            this.$summernote.summernote('focus');
            this.trigger('change');
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', end, {once: true});
    }

    resizeSelectedImage(width) {
        const image = this.selectedTenantImage;
        if (!image) return;
        const editableWidth = this.$el.find('.note-editable').width() || 560;
        const nextWidth = Math.round(Math.max(80, Math.min(width, editableWidth)));
        image.dataset.nexaImageWidth = String(nextWidth);
        image.setAttribute('width', String(nextWidth));
        image.style.width = `${nextWidth}px`;
        image.style.height = 'auto';
        this.positionImageResizeHandle();
    }

    removeImageResizeHandle(unbind = true) {
        if (unbind && this.imageEditorClickHandler) {
            this.$el?.find('.note-editable').get(0)?.removeEventListener('click', this.imageEditorClickHandler);
            this.imageEditorClickHandler = null;
        }
        if (this.imageResizePositionHandler) {
            window.removeEventListener('resize', this.imageResizePositionHandler);
            document.removeEventListener('scroll', this.imageResizePositionHandler, true);
            this.imageResizePositionHandler = null;
        }
        this.selectedTenantImage?.classList.remove('is-selected');
        this.imageResizeHandle?.remove();
        this.selectedTenantImage = null;
        this.imageResizeHandle = null;
    }

    fetch() {
        const data = super.fetch();
        if (data[this.name]) data[this.name] = TenantImages.normalizeHtml(data[this.name]);
        return data;
    }

    uploadTenantImage(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onerror = () => reject(new Error('The image could not be read.'));
            reader.onload = async event => {
                try {
                    resolve(await Espo.Ajax.postRequest('Nexa/files/images', {
                        name: file.name,
                        mimeType: file.type,
                        data: event.target.result,
                    }));
                } catch (error) {
                    reject(error);
                }
            };
            reader.readAsDataURL(file);
        });
    }

    uploadTenantFile(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onerror = () => reject(new Error('The file could not be read.'));
            reader.onload = async event => {
                try {
                    resolve(await Espo.Ajax.postRequest('Nexa/files', {
                        name: file.name,
                        mimeType: file.type,
                        data: event.target.result,
                    }));
                } catch (error) {
                    reject(error);
                }
            };
            reader.readAsDataURL(file);
        });
    }

    escapeFileName(value) {
        const node = document.createElement('span');
        node.textContent = String(value || '');
        return node.innerHTML;
    }
});
