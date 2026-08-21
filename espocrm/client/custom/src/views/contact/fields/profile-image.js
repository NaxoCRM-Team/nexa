define('custom:views/contact/fields/profile-image', ['views/fields/image'], Dep => {
    /**
     * Shows a local portrait immediately while Espo creates the tenant-scoped
     * Attachment. Once upload completes, the normal protected image URL takes over.
     */
    return class extends Dep {
        // Must match GetProfileImage.php's ALLOWED_TYPE_LIST exactly - the
        // default accept="image/*" let a user pick a format (e.g. .bmp,
        // .tiff, .heic) that would upload fine but could then never actually
        // be displayed, since the server-side endpoint that serves it back
        // rejects anything outside this list.
        static ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        setup() {
            super.setup();

            // The only reliable signal that an attachment id is actually
            // persisted server-side (and therefore safe to fetch the
            // protected preview for) is a real sync - not just "the model
            // currently holds an id", which is equally true the instant a
            // fresh, unsaved upload sets it in memory.
            this.savedAttachmentId = this.model.get(this.idName) || null;

            this.listenTo(this.model, 'sync', () => {
                const id = this.model.get(this.idName);
                this.savedAttachmentId = id || null;
                if (id && !this.model.isNew()) {
                    this.loadProtectedPreview(id);
                }
            });

            this.on('remove', () => {
                this.releaseLocalPreview();
                this.releaseProtectedPreview();
            });
        }

        afterRender() {
            super.afterRender();

            this.$el.addClass('nexa-profile-image-field');

            const id = this.model.get(this.idName);
            if (id && id === this.savedAttachmentId && !this.model.isNew()) {
                this.loadProtectedPreview(id);
            }

            if (this.mode !== this.MODE_EDIT) {
                return;
            }

            const allowedTypes = this.constructor.ALLOWED_MIME_TYPES;
            const label = this.$el.find('.attach-file-label')
                .attr('title', this.translate('Upload profile image', 'labels', 'Contact'))
                .attr('aria-label', this.translate('Upload profile image', 'labels', 'Contact'));
            label.find('.fa-paperclip')
                .removeClass('fa-paperclip')
                .addClass('fa-camera');
            label.find('input[type="file"]').attr('accept', allowedTypes.join(','));

            if (!this.$el.find('.nexa-profile-image-hint').length) {
                $('<small>')
                    .addClass('nexa-profile-image-hint text-muted')
                    .text('JPG, PNG, GIF or WEBP')
                    .insertAfter(label);
            }

            if (!this.model.get(this.idName) && !this.localPreviewUrl) {
                this.renderPlaceholder();
            }

        }

        uploadFile(file) {
            this.releaseLocalPreview();
            this.localPreviewUrl = URL.createObjectURL(file);

            super.uploadFile(file);
        }

        getBoxPreviewHtml(name, type, id) {
            if (!id && this.localPreviewUrl) {
                return $('<img>')
                    .attr('src', this.localPreviewUrl)
                    .attr('alt', this.translate('Contact profile preview', 'labels', 'Contact'))
                    .addClass('nexa-profile-image-preview')
                    .get(0).outerHTML;
            }

            if (id && this.localPreviewUrl) {
                return $('<img>')
                    .attr('src', this.localPreviewUrl)
                    .attr('alt', this.translate('Contact profile preview', 'labels', 'Contact'))
                    .addClass('nexa-profile-image-preview')
                    .get(0).outerHTML;
            }

            if (id) {
                return this.getProtectedPreviewHtml(id);
            }

            return super.getBoxPreviewHtml(name, type, id);
        }

        getEditPreview(name, type, id) {
            if (this.localPreviewUrl) {
                return $('<img>')
                    .attr('src', this.localPreviewUrl)
                    .attr('alt', this.translate('Contact profile preview', 'labels', 'Contact'))
                    .addClass('nexa-profile-image-preview')
                    .get(0).outerHTML;
            }

            return this.getProtectedPreviewHtml(id);
        }

        getDetailPreview(name, type, id) {
            if (this.localPreviewUrl) {
                return $('<img>')
                    .attr('src', this.localPreviewUrl)
                    .attr('alt', this.translate('Contact profile preview', 'labels', 'Contact'))
                    .addClass('nexa-profile-image-preview')
                    .get(0).outerHTML;
            }

            return this.getProtectedPreviewHtml(id);
        }

        setAttachment(attachment, ui) {
            super.setAttachment(attachment, ui);

            // Don't fetch the protected preview here: this attachment was
            // just uploaded and isn't associated with the contact server-side
            // yet (that only happens once the edit form is actually saved) -
            // GetProfileImage.php correctly 404s until then. The local blob
            // preview from uploadFile() already shows it immediately; the
            // model's own 'sync' listener (in setup()) picks up the real
            // protected preview once a save actually happens.
        }

        deleteAttachment() {
            super.deleteAttachment();
            this.releaseLocalPreview();
            this.releaseProtectedPreview();
            this.renderPlaceholder();
        }

        getProtectedPreviewHtml(id) {
            return $('<span>')
                .addClass('nexa-profile-image-placeholder nexa-profile-image-loading')
                .attr('data-profile-attachment-id', id)
                .attr('role', 'img')
                .attr('aria-label', this.translate('Contact profile image', 'labels', 'Contact'))
                .append($('<span>').addClass('fas fa-user').attr('aria-hidden', 'true'))
                .get(0).outerHTML;
        }

        async loadProtectedPreview(id) {
            if (!id || this.protectedPreviewId === id) {
                return;
            }

            this.protectedPreviewId = id;

            try {
                const payload = await Espo.Ajax.getRequest(
                    `Nexa/contact-profile-image/${encodeURIComponent(id)}`
                );

                if (this.model.get(this.idName) !== id || !payload?.data || !payload?.mimeType) {
                    return;
                }

                const binary = atob(payload.data);
                const bytes = new Uint8Array(binary.length);
                for (let index = 0; index < binary.length; index++) {
                    bytes[index] = binary.charCodeAt(index);
                }

                this.releaseLocalPreview();
                this.releaseProtectedPreview();
                this.protectedPreviewId = id;
                this.protectedPreviewUrl = URL.createObjectURL(
                    new Blob([bytes], {type: payload.mimeType})
                );

                const selector = `[data-profile-attachment-id="${id}"]`;
                this.$el.find(selector)
                    .removeClass('nexa-profile-image-loading')
                    .empty()
                    .append(
                        $('<img>')
                            .attr('src', this.protectedPreviewUrl)
                            .attr('alt', this.translate('Contact profile image', 'labels', 'Contact'))
                            .addClass('nexa-profile-image-preview')
                    );
            } catch (error) {
                this.protectedPreviewId = null;
            }
        }

        renderPlaceholder() {
            if (!this.$attachment || this.model.get(this.idName)) {
                return;
            }

            const label = this.translate('Contact profile image', 'labels', 'Contact');
            const $placeholder = $('<div>')
                .addClass('nexa-profile-image-placeholder')
                .attr('role', 'img')
                .attr('aria-label', label)
                .append($('<span>').addClass('fas fa-user').attr('aria-hidden', 'true'));

            this.$attachment.empty().append($placeholder);
        }

        releaseLocalPreview() {
            if (!this.localPreviewUrl) {
                return;
            }

            URL.revokeObjectURL(this.localPreviewUrl);
            this.localPreviewUrl = null;
        }

        releaseProtectedPreview() {
            if (this.protectedPreviewUrl) {
                URL.revokeObjectURL(this.protectedPreviewUrl);
            }

            this.protectedPreviewUrl = null;
            this.protectedPreviewId = null;
        }
    };
});
