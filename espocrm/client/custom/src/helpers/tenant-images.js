define('custom:helpers/tenant-images', [], () => {
    const responseCache = new Map();

    const attachmentId = image => {
        if (image.dataset.nexaAttachmentId) return image.dataset.nexaAttachmentId;
        const match = String(image.getAttribute('src') || '').match(/[?&]entryPoint=attachment(?:&amp;|&)id=([^&#]+)/i);
        return match ? decodeURIComponent(match[1]) : '';
    };

    const load = id => {
        if (!responseCache.has(id)) {
            responseCache.set(id, Espo.Ajax.getRequest(`Nexa/attachment-file/${encodeURIComponent(id)}`)
                .then(response => {
                    if (!response?.data || !response?.mimeType) throw new Error('Image data is unavailable.');
                    return `data:${response.mimeType};base64,${response.data}`;
                })
                .catch(error => {
                    responseCache.delete(id);
                    throw error;
                }));
        }
        return responseCache.get(id);
    };

    const hydrate = root => Promise.all([...root.querySelectorAll('img')].map(async image => {
        const id = attachmentId(image);
        if (!id) return;
        image.dataset.nexaAttachmentId = id;
        image.classList.add('nexa-tenant-inline-image', 'is-loading');
        try {
            image.src = await load(id);
            image.classList.remove('is-loading', 'is-unavailable');
        } catch (error) {
            image.classList.remove('is-loading');
            image.classList.add('is-unavailable');
            image.alt = image.alt || 'Image unavailable';
        }
    }));

    const normalizeHtml = html => {
        const root = document.createElement('div');
        root.innerHTML = String(html || '');
        root.querySelectorAll('img').forEach(image => {
            const id = attachmentId(image);
            if (!id) return;
            image.dataset.nexaAttachmentId = id;
            image.src = `?entryPoint=attachment&id=${encodeURIComponent(id)}`;
            image.classList.remove('is-loading', 'is-unavailable', 'is-selected');
            const width = Math.round(Number.parseFloat(image.dataset.nexaImageWidth || image.style.width || image.width));
            if (width > 0) {
                image.dataset.nexaImageWidth = String(width);
                image.setAttribute('width', String(width));
                image.style.width = `${width}px`;
                image.style.height = 'auto';
            }
        });
        return root.innerHTML;
    };

    return {attachmentId, hydrate, load, normalizeHtml};
});
