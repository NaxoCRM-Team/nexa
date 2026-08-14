define('custom:views/contact/fields/name-v2', ['views/fields/person-name'], Dep => {
    /**
     * Nexa Contact forms use first and last name only. The underlying
     * salutation value remains readable for imported legacy records.
     */
    return class extends Dep {
        editTemplate = 'custom:contact/fields/name/edit-v2';
        listTemplate = 'custom:contact/fields/name/list-link-v2';
        listLinkTemplate = 'custom:contact/fields/name/list-link-v2';

        setup() {
            super.setup();

            this.once('remove', () => this.releaseListAvatar());
        }

        data() {
            const data = super.data();
            const displayName = this.getFormattedValue() || this.model.get('name') || 'Unnamed contact';
            const initialSource = this.model.get('firstName') || this.model.get('lastName') || displayName;

            data.displayName = displayName;
            data.initial = String(initialSource).trim().charAt(0).toUpperCase() || '?';
            data.profileImageId = this.model.get('profileImageId');
            data.doNotContact = Boolean(this.model.get('doNotContact'));
            data.doNotContactTitle = this.communicationPreferenceTitle();

            return data;
        }

        communicationPreferenceTitle() {
            const channels = String(this.model.get('doNotContactChannels') || '')
                .split(',').filter(Boolean).map(channel => channel === 'postal' ? 'postal mail' : channel);

            return channels.length
                ? `Do not contact: ${channels.join(', ')}`
                : 'Do not contact';
        }

        afterRender() {
            super.afterRender();

            if (!this.isListMode()) return;

            const imageId = this.model.get('profileImageId');
            if (imageId) this.loadListAvatar(imageId);
        }

        async loadListAvatar(imageId) {
            try {
                const payload = await Espo.Ajax.getRequest(
                    `Nexa/contact-profile-image/${encodeURIComponent(imageId)}`
                );

                if (this.model.get('profileImageId') !== imageId || !payload?.data || !payload?.mimeType) return;

                const binary = atob(payload.data);
                const bytes = new Uint8Array(binary.length);
                for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);

                this.releaseListAvatar();
                this.listAvatarUrl = URL.createObjectURL(new Blob([bytes], {type: payload.mimeType}));

                const avatar = this.element?.querySelector('.nexa-contact-list-avatar');
                if (!avatar) return;

                const image = document.createElement('img');
                image.src = this.listAvatarUrl;
                image.alt = '';
                image.className = 'nexa-contact-list-avatar-image';
                avatar.replaceChildren(image);
                avatar.classList.add('nexa-contact-list-avatar--image');
            } catch (error) {
                // The initial remains visible when an image was removed or cannot be read.
            }
        }

        releaseListAvatar() {
            if (!this.listAvatarUrl) return;

            URL.revokeObjectURL(this.listAvatarUrl);
            this.listAvatarUrl = null;
        }
    };
});
