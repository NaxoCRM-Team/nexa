define('custom:helpers/tenant-files', [], () => {
    let installed = false;

    const download = async (id, name) => {
        const response = await Espo.Ajax.getRequest(`Nexa/attachment-file/${encodeURIComponent(id)}`);
        if (!response?.data) throw new Error('File data is unavailable.');
        const binary = atob(response.data);
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
        const url = URL.createObjectURL(new Blob([bytes], {type: response.mimeType || 'application/octet-stream'}));
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = response.name || name || 'download';
        document.body.append(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    const install = () => {
        if (installed) return;
        installed = true;
        document.addEventListener('click', event => {
            const link = event.target.closest('a[data-nexa-file-id]');
            if (!link) return;
            event.preventDefault();
            if (link.dataset.loading === 'true') return;
            link.dataset.loading = 'true';
            link.setAttribute('aria-busy', 'true');
            download(link.dataset.nexaFileId, link.dataset.nexaFileName)
                .catch(() => Espo.Ui.error('The file could not be downloaded.'))
                .finally(() => {
                    link.dataset.loading = 'false';
                    link.removeAttribute('aria-busy');
                });
        });
    };

    return {download, install};
});
