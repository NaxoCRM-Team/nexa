define('custom:views/account/fields/name-list', ['views/fields/varchar'], Dep => class extends Dep {
    listTemplate = 'custom:account/fields/name-list';
    listLinkTemplate = 'custom:account/fields/name-list';

    setup() {
        super.setup();
        this.once('remove', () => this.releaseAvatar());
    }

    data() {
        const data = super.data();
        const displayName = this.model.get(this.name) || 'Unnamed account';

        return {...data, displayName, initial: String(displayName).trim().charAt(0).toUpperCase() || '?'};
    }

    afterRender() {
        super.afterRender();

        if (this.model.id && (this.model.get('companyLogoId') || this.model.get('website'))) {
            this.loadAvatar();
        }
    }

    async loadAvatar() {
        const accountId = this.model.id;

        try {
            const payload = await Espo.Ajax.getRequest(`Nexa/account/${encodeURIComponent(accountId)}/avatar`);
            if (this.model.id !== accountId || !payload?.available || !payload.data || !payload.mimeType) return;

            const url = this.payloadUrl(payload);
            const avatar = this.element?.querySelector('.nexa-account-list-avatar');
            if (!avatar) {
                URL.revokeObjectURL(url);
                return;
            }

            this.releaseAvatar();
            this.avatarUrl = url;
            const image = document.createElement('img');
            image.src = url;
            image.alt = '';
            image.className = 'nexa-account-list-avatar-image';
            avatar.replaceChildren(image);
            avatar.classList.add('nexa-account-list-avatar--image');
        } catch (error) {
            // The initial stays visible when no usable logo or favicon exists.
        }
    }

    payloadUrl(payload) {
        const binary = atob(payload.data);
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);

        return URL.createObjectURL(new Blob([bytes], {type: payload.mimeType}));
    }

    releaseAvatar() {
        if (!this.avatarUrl) return;

        URL.revokeObjectURL(this.avatarUrl);
        this.avatarUrl = null;
    }
});
