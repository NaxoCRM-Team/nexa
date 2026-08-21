(() => {
    // The stock ?entryPoint=download / ?entryPoint=image URLs (built by
    // several different core field-view classes across the whole app -
    // views/fields/file, views/fields/attachment-multiple, the avatar
    // field, the image-preview modal, etc.) rely on cookie-based
    // entry-point auth, which intermittently rejects genuinely valid
    // sessions (a pre-existing race condition, confirmed separately, not
    // something worth chasing further - see the Avatar entry point's own
    // NoAuth workaround for the same category of problem). Rather than
    // overriding every view class that can build one of these URLs, this
    // intercepts the two shapes those URLs always take, wherever they
    // appear, and serves the same bytes through Nexa/attachment-file/{id}
    // instead - a normal API route, authenticated via the SPA's standard
    // Bearer-token auth, which has never shown this problem.

    function attachmentIdFromUrl(url) {
        try {
            const parsed = new URL(url, window.location.origin);
            return parsed.searchParams.get('id');
        } catch (e) {
            return null;
        }
    }

    function base64ToBlob(base64, mimeType) {
        const binary = atob(base64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return new Blob([bytes], {type: mimeType || 'application/octet-stream'});
    }

    async function fetchAttachment(id) {
        return Espo.Ajax.getRequest(`Nexa/attachment-file/${encodeURIComponent(id)}`);
    }

    // --- Downloads: delegated click, no MutationObserver needed - covers
    // dynamically-rendered links for free. ---
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href*="entryPoint=download"]');
        if (!link) return;

        const id = attachmentIdFromUrl(link.href);
        if (!id) return;

        event.preventDefault();

        fetchAttachment(id).then(payload => {
            if (!payload?.data) return;

            const blob = base64ToBlob(payload.data, payload.mimeType);
            const url = URL.createObjectURL(blob);

            const tempLink = document.createElement('a');
            tempLink.href = url;
            tempLink.download = payload.name || 'download';
            document.body.appendChild(tempLink);
            tempLink.click();
            tempLink.remove();

            window.setTimeout(() => URL.revokeObjectURL(url), 30000);
        }).catch(() => {});
    }, true);

    // --- Inline image previews: img[src] loads immediately on insertion,
    // so this needs an observer rather than click delegation. ---
    function swapImage(img) {
        if (img.dataset.nexaAttachmentPatched) return;
        img.dataset.nexaAttachmentPatched = 'true';

        const id = attachmentIdFromUrl(img.src);
        if (!id) return;

        fetchAttachment(id).then(payload => {
            if (!payload?.data) return;

            const blob = base64ToBlob(payload.data, payload.mimeType);
            img.src = URL.createObjectURL(blob);
        }).catch(() => {});
    }

    function scanForImages(root) {
        root.querySelectorAll('img[src*="entryPoint=image"]').forEach(swapImage);
    }

    function startObserving() {
        scanForImages(document.body);

        let debounceTimer = null;
        const observer = new MutationObserver(() => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => scanForImages(document.body), 25);
        });
        observer.observe(document.body, {childList: true, subtree: true});
    }

    if (document.body) {
        startObserving();
    } else {
        document.addEventListener('DOMContentLoaded', startObserving, {once: true});
    }
})();
