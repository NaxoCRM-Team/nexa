(() => {
    let editor;
    let session;
    let images = [];
    const imageSources = new Map();
    const revokedSources = new Set();
    let pendingImage;
    let confirmImage;
    const status = document.querySelector('#status');
    const clean = html => DOMPurify.sanitize(html, {
        FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 'select', 'meta', 'link', 'base'],
        FORBID_ATTR: ['srcdoc'],
    });
    const reply = (type, extra = {}) => parent.postMessage({type, session, ...extra}, location.origin);
    window.addEventListener('message', event => {
        if (event.source !== parent || event.origin !== location.origin) return;
        const data = event.data;
        if (!data || typeof data.session !== 'string' || data.session.length > 64) return;
        if (session === data.session && data.type === 'nexa-email-designer-images' && Array.isArray(data.images)) {
            images = data.images.filter(image => typeof image.id === 'string' && typeof image.name === 'string' &&
                (typeof image.url === 'string' || (image.requiresPublication === true && typeof image.assetId === 'string')));
            renderImages();
        }
        if (session === data.session && data.type === 'nexa-email-designer-image-result') {
            const image = data.image;
            const insert = pendingImage?.insert;
            const approved = images.find(item => item.id === pendingImage?.id && (
                (item.requiresPublication === true && data.requestedId === item.id && item.assetId === image?.assetId && typeof image.url === 'string' && image.id?.startsWith('mail-')) ||
                (item.id === image?.id && item.url === image.url)
            ));
            pendingImage = null;
            if (!approved || !/^data:image\/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$/.test(image.preview || '') || image.preview.length > 12_000_000) {
                document.querySelector('#image-status').textContent = 'Image unavailable'; return;
            }
            imageSources.set(image.id, image.url);
            if (approved.requiresPublication && !images.some(item => item.id === image.id)) images.unshift(image);
            const existing = editor.getWrapper().find('img').filter(component => component.getAttributes().src === image.url);
            existing.forEach(component => component.addAttributes({src: image.preview, 'data-nexa-email-image': image.id}));
            if (insert) {
                const added = editor.addComponents({type: 'nexa-email-image', attributes: {src: image.preview, alt: image.alt || image.name, 'data-nexa-email-image': image.id}, style: {'max-width': '100%', height: 'auto'}});
                editor.select(added[0]);
            }
            document.querySelector('#image-status').textContent = '';
            renderImages();
            loadNextPreview();
        }
        if (session === data.session && data.type === 'nexa-email-designer-image-revoked') {
            const image = images.find(item => item.id === data.id);
            if (image?.url) revokedSources.add(image.url);
            images = images.filter(item => item.id !== data.id); pendingImage = null;
            renderImages(); document.querySelector('#image-status').textContent = 'Email image revoked. Remove it from drafts that use it.';
        }
        if (session === data.session && data.type === 'nexa-email-designer-image-error') {
            pendingImage = null; document.querySelector('#image-status').textContent = 'Images unavailable. Try again.';
        }
        if (data.type === 'nexa-email-designer-init' && !editor) {
            if (typeof data.html !== 'string' || data.html.length > 2_000_000) return;
            session = data.session;
            try {
                editor = grapesjs.init({
                    container: '#canvas', height: '100%', width: 'auto', fromElement: false,
                    storageManager: false, noticeOnUnload: false,
                    panels: {defaults: []}, assetManager: {upload: false, assets: []},
                    blockManager: {appendTo: '#blocks'}, styleManager: {appendTo: '#styles'}, traitManager: {appendTo: '#traits'},
                    deviceManager: {devices: [{name: 'Desktop', width: ''}, {name: 'Mobile', width: '320px', widthMedia: '480px'}]},
                    parser: {optionsHtml: {allowScripts: false, allowUnsafeAttr: false}}, jsInHtml: false,
                    plugins: ['grapesjs-preset-newsletter'],
                    pluginsOpts: {'grapesjs-preset-newsletter': {
                        blocks: ['sect100', 'sect50', 'sect30', 'sect37', 'text', 'text-sect', 'button', 'divider', 'quote'],
                        useCustomTheme: false, showBlocksOnLoad: false, showStylesOnChange: false,
                    }},
                });
                editor.DomComponents.addType('nexa-email-image', {extend: 'image', model: {defaults: {traits: ['alt']}}});
                editor.on('load', () => {
                    editor.setComponents(clean(data.html));
                    const wrapperStyle = editor.getWrapper().getStyle();
                    if (!wrapperStyle['font-family']) editor.getWrapper().addStyle({'font-family': 'Arial, sans-serif'});
                    editor.runCommand('sw-visibility');
                    status.textContent = 'Ready';
                    reply('nexa-email-designer-ready');
                    loadNextPreview();
                    // Native blocks remain draggable; Enter/click also inserts for keyboard and touch users.
                    document.querySelectorAll('.gjs-block').forEach((node, index) => {
                        const block = editor.Blocks.getAll().at(index);
                        node.setAttribute('role', 'button'); node.setAttribute('tabindex', '0');
                        const insert = () => {const added = editor.addComponents(block.get('content')); if (added[0]) editor.select(added[0]);};
                        node.addEventListener('click', insert);
                        node.addEventListener('keydown', key => {if (key.key === 'Enter' || key.key === ' ') {key.preventDefault(); insert();}});
                    });
                });
                editor.on('canvas:frame:load', () => editor.Canvas.getDocument()?.addEventListener('keydown', key => {if (key.key === 'Escape') reply('nexa-email-designer-cancel');}));
            } catch (error) {
                status.textContent = 'Designer could not be loaded';
                reply('nexa-email-designer-error');
            }
        }
        if (data.type === 'nexa-email-designer-export' && editor && session === data.session) {
            try {
                const html = clean(editor.runCommand('gjs-get-inlined-html'));
                const template = document.createElement('template');
                template.innerHTML = html;
                template.content.querySelectorAll('img').forEach(image => {
                    const url = imageSources.get(image.getAttribute('data-nexa-email-image'));
                    if (revokedSources.has(url || image.getAttribute('src'))) throw new Error('A hosted image was revoked.');
                    if (url) image.setAttribute('src', url);
                    image.removeAttribute('data-nexa-email-image');
                });
                if (template.innerHTML.length > 2_000_000) throw new Error('Email content is too large.');
                reply('nexa-email-designer-content', {html: template.innerHTML});
            } catch (error) {reply('nexa-email-designer-error', {reason: error.message === 'A hosted image was revoked.' ? 'revoked-image' : 'export-failed'});}
        }
    });
    const renderImages = () => {
        const list = document.querySelector('#images'); list.replaceChildren();
        const search = document.querySelector('#image-search').value.trim().toLocaleLowerCase();
        images.filter(image => !image.legacy && image.name.toLocaleLowerCase().includes(search)).forEach(image => {
            const row = document.createElement('div'); row.className = 'image-row';
            const button = document.createElement('button'); button.type = 'button'; button.textContent = image.name;
            const kind = document.createElement('small'); kind.textContent = image.requiresPublication ? 'Public asset' : 'Email copy'; kind.setAttribute('aria-hidden', 'true'); button.append(kind);
            button.title = image.requiresPublication ? 'Publish a separate email copy' : 'Insert hosted email image';
            button.addEventListener('click', () => {
                if (pendingImage) return;
                if (image.requiresPublication) {showImageConfirmation(image, 'publish'); return;}
                pendingImage = {id: image.id, insert: true}; document.querySelector('#image-status').textContent = 'Loading image';
                reply('nexa-email-designer-image', {id: image.id});
            });
            row.append(button);
            if (image.id.startsWith('mail-')) {
                const revoke = document.createElement('button'); revoke.type = 'button'; revoke.textContent = 'Revoke';
                revoke.title = `Stop serving ${image.name}`; revoke.setAttribute('aria-label', `Revoke ${image.name}`);
                revoke.addEventListener('click', () => {if (!pendingImage) showImageConfirmation(image, 'revoke');});
                row.append(revoke);
            }
            list.append(row);
        });
        document.querySelector('#image-status').textContent = list.childElementCount ? '' : 'No email images';
        loadNextPreview();
    };
    const showImageConfirmation = (image, action) => {
        confirmImage = {image, action};
        document.querySelector('#image-confirm-title').textContent = action === 'publish' ? 'Publish an email image copy?' : 'Revoke this email image?';
        document.querySelector('#image-confirm-text').textContent = action === 'publish'
            ? 'Anyone with the new image link can view this public copy. It remains available even if you cancel this design, archive the original, or make the original Internal. Use Revoke to stop serving the email copy. The original file is not changed.'
            : 'This stops serving the copy in all emails and drafts that use its link, including emails already sent. The original asset is not deleted. Images already cached by an email provider cannot be recalled.';
        document.querySelector('#image-confirm-accept').textContent = action === 'publish' ? 'Publish and insert' : 'Revoke image';
        document.querySelector('#image-confirm').showModal(); document.querySelector('#image-confirm-cancel').focus();
    };
    document.querySelector('#image-confirm-cancel').addEventListener('click', () => {confirmImage = null; document.querySelector('#image-confirm').close();});
    document.querySelector('#image-confirm-accept').addEventListener('click', () => {
        const confirmation = confirmImage; confirmImage = null; document.querySelector('#image-confirm').close();
        if (!confirmation || pendingImage) return;
        const {image, action} = confirmation;
        pendingImage = {id: image.id, insert: action === 'publish'};
        document.querySelector('#image-status').textContent = action === 'publish' ? 'Publishing email image' : 'Revoking email image';
        reply(action === 'publish' ? 'nexa-email-designer-image-publish' : 'nexa-email-designer-image-revoke', {id: image.id, assetId: image.assetId});
    });
    document.querySelector('#image-confirm').addEventListener('cancel', event => {event.stopPropagation(); confirmImage = null;});
    const loadNextPreview = () => {
        if (!editor || pendingImage) return;
        const components = editor.getWrapper()?.find('img') || [];
        const image = images.find(item => components.some(component => component.getAttributes().src === item.url));
        if (image) {pendingImage = {id: image.id, insert: false}; reply('nexa-email-designer-image', {id: image.id});}
    };
    document.querySelector('#image-search').addEventListener('input', renderImages);
    document.querySelector('#device').addEventListener('change', event => editor?.setDevice(event.target.value));
    document.querySelector('#panel').addEventListener('change', event => document.querySelector('main').classList.toggle('show-settings', event.target.value === 'settings'));
    document.querySelector('#undo').addEventListener('click', () => editor?.UndoManager.undo());
    document.querySelector('#redo').addEventListener('click', () => editor?.UndoManager.redo());
    document.querySelector('#outlines').addEventListener('change', event => {if (editor) editor[event.target.checked ? 'runCommand' : 'stopCommand']('sw-visibility');});
    document.addEventListener('keydown', event => {if (event.key === 'Escape' && !document.querySelector('#image-confirm').open) reply('nexa-email-designer-cancel');});
    window.addEventListener('pagehide', () => editor?.destroy());
})();
