(() => {
    const style = document.createElement('style');
    style.textContent = `
.site-brand{position:relative;z-index:2;display:flex;min-height:64px;align-items:center;justify-content:space-between;gap:24px;padding:10px max(24px,calc((100vw - 1160px)/2));border-bottom:1px solid color-mix(in srgb,var(--text) 12%,transparent);background:color-mix(in srgb,var(--background) 96%,transparent)}
.site-brand>div{display:flex;min-width:0;align-items:center;gap:11px}.site-brand-mark{display:grid;width:36px;height:36px;flex:0 0 36px;place-items:center;border-radius:6px;background:var(--primary);color:#fff;font-size:17px;font-weight:800}.site-brand strong{overflow:hidden;font-size:16px;text-overflow:ellipsis;white-space:nowrap}.site-brand>span:last-child{color:color-mix(in srgb,var(--text) 68%,transparent);font-size:13px;font-weight:650}
.form-success{display:none;width:100%;min-height:100%;align-content:center;justify-items:center;padding:44px;background:#fff;color:#172f35;text-align:center}.form-success-icon{display:grid;width:54px;height:54px;margin-bottom:18px;place-items:center;border-radius:50%;background:#e3f4ef;color:#087d71;font-size:26px;font-weight:800}.form-success-label{color:#087d71;font-size:12px;font-weight:800;text-transform:uppercase}.form-success h2{margin:8px 0 10px;color:#172f35;font-size:34px;line-height:1.15}.form-success>p{max-width:520px;margin:0;color:#52666b;font-size:16px;line-height:1.6;white-space:pre-line}.form-success .form-redirect-status{margin-top:14px;font-size:13px;font-weight:650}.form-success .button{min-width:128px}
.form-dialog.is-submitted iframe,.form-dialog.is-submitted .form-loading,.form.is-embedded.is-submitted iframe{display:none}.form-dialog.is-submitted .form-success{display:grid;height:calc(100% - 62px)}.form.is-embedded.is-submitted .form-success{display:grid;min-height:560px;border:1px solid color-mix(in srgb,var(--text) 18%,transparent);border-radius:8px}
@media(max-width:600px){.site-brand{min-height:58px;padding:10px 18px}.site-brand>span:last-child{display:none}.form-success{padding:30px 22px}}
`;
    document.head.append(style);

    const openerByDialog = new WeakMap();
    const redirectTimerByContainer = new WeakMap();

    const dialogFor = id => document.querySelector(
        `[data-nexa-form-dialog="${CSS.escape(id)}"]`
    );

    const openDialog = (dialog, opener) => {
        if (!dialog || typeof dialog.showModal !== 'function') return;

        openerByDialog.set(dialog, opener);
        dialog.showModal();
        dialog.querySelector('[data-nexa-form-close]')?.focus();
    };

    const closeDialog = dialog => {
        if (!dialog?.open) return;

        window.clearInterval(redirectTimerByContainer.get(dialog));
        dialog.close();
        openerByDialog.get(dialog)?.focus();
    };

    const safeRedirectUrl = value => {
        if (!value) return null;

        try {
            const url = new URL(value, window.location.href);

            return ['http:', 'https:'].includes(url.protocol) ? url : null;
        } catch {
            return null;
        }
    };

    const showSuccess = (container, data) => {
        if (!container) return;

        const text = container.querySelector('[data-nexa-form-success-text]');
        const status = container.querySelector('[data-nexa-form-redirect-status]');
        const action = container.querySelector('[data-nexa-form-success-action]');
        const redirectUrl = safeRedirectUrl(data.redirectUrl);
        let remaining = Math.max(1, Math.min(30, Number(data.redirectDelaySeconds || 4)));

        text.textContent = String(data.successText || 'Thank you. Your response has been received.');
        container.classList.add('is-submitted');

        if (!redirectUrl) {
            status.hidden = true;
            action.textContent = 'Close';
            action.removeAttribute('href');
            action.setAttribute('data-nexa-form-close', '');
            action.focus();

            return;
        }

        action.textContent = 'Continue now';
        action.href = redirectUrl.href;
        action.removeAttribute('data-nexa-form-close');
        status.hidden = false;

        const updateStatus = () => {
            status.textContent = `Continuing to ${redirectUrl.hostname} in ${remaining} ${remaining === 1 ? 'second' : 'seconds'}...`;
        };

        updateStatus();
        redirectTimerByContainer.set(container, window.setInterval(() => {
            remaining -= 1;

            if (remaining <= 0) {
                window.clearInterval(redirectTimerByContainer.get(container));
                window.location.assign(redirectUrl.href);

                return;
            }

            updateStatus();
        }, 1000));
        action.focus();
    };

    document.addEventListener('click', event => {
        const opener = event.target.closest('[data-nexa-form-open]');

        if (opener) {
            event.preventDefault();
            openDialog(dialogFor(opener.dataset.nexaFormOpen), opener);

            return;
        }

        const closer = event.target.closest('[data-nexa-form-close]');

        if (closer) {
            const dialog = closer.closest('dialog');

            if (dialog) closeDialog(dialog);
            else closer.closest('[data-nexa-form-container]')?.classList.remove('is-submitted');
        }
    });

    document.querySelectorAll('[data-nexa-form-dialog]').forEach(dialog => {
        const frame = dialog.querySelector('[data-nexa-form-frame]');
        let attempts = 0;
        const revealWhenReady = () => {
            attempts += 1;

            try {
                const content = frame?.contentDocument?.querySelector('.container.content');

                if (content?.children.length) {
                    dialog.classList.add('is-ready');

                    return;
                }
            } catch {
                dialog.classList.add('is-ready');

                return;
            }

            if (attempts < 60) window.setTimeout(revealWhenReady, 250);
            else dialog.classList.add('is-ready');
        };

        frame?.addEventListener('load', revealWhenReady);
        revealWhenReady();

        dialog.addEventListener('click', event => {
            const bounds = dialog.getBoundingClientRect();
            const outside = event.clientX < bounds.left || event.clientX > bounds.right ||
                event.clientY < bounds.top || event.clientY > bounds.bottom;

            if (outside) closeDialog(dialog);
        });

        dialog.addEventListener('close', () => openerByDialog.get(dialog)?.focus());
    });

    document.querySelectorAll('.form.is-embedded [data-nexa-form-frame]').forEach(frame => {
        const resize = () => {
            try {
                const height = frame.contentDocument?.documentElement?.scrollHeight;

                if (height) frame.style.height = `${Math.max(560, height + 8)}px`;
            } catch {
                // Cross-origin forms retain the stable minimum height.
            }
        };

        frame.addEventListener('load', resize);
        window.setTimeout(resize, 600);
    });

    window.addEventListener('message', event => {
        if (event.origin !== window.location.origin || event.data?.type !== 'nexa:form-submitted') return;

        const frame = [...document.querySelectorAll('[data-nexa-form-frame]')]
            .find(candidate => candidate.contentWindow === event.source);

        showSuccess(frame?.closest('[data-nexa-form-container]'), event.data);
    });
})();
