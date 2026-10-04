(() => {
    'use strict';

    const script = document.currentScript;
    const publicKey = script?.dataset.nexaCookieKey || '';
    if (!/^[a-f0-9]{48}$/.test(publicKey) || window.NexaConsent?.publicKey === publicKey) return;

    const source = new URL(script.src, window.location.href);
    const marker = '/client/custom/nexa-cookie-consent.js';
    const root = source.pathname.endsWith(marker) ? source.pathname.slice(0, -marker.length) : '';
    const apiUrl = `${source.origin}${root}/api/v1`;
    const requestedMode = script.dataset.nexaCookieMode || 'managed';
    let config = null;
    let visitorId = null;
    let storageKey = null;
    let resolveReady;
    const ready = new Promise(resolve => { resolveReady = resolve; });
    const uuid = () => crypto.randomUUID?.() || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, value => {
        const random = Math.random() * 16 | 0;
        return (value === 'x' ? random : (random & 3 | 8)).toString(16);
    });
    const escape = value => {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    };

    const client = window.NexaConsent = {
        publicKey,
        ready,
        sync: input => ready.then(() => persist(input || {})),
        getPreferences: () => {
            if (!storageKey) return null;
            try { return JSON.parse(window.localStorage.getItem(storageKey)); } catch (error) { return null; }
        },
    };

    fetch(`${apiUrl}/Nexa/public/cookies/config/${publicKey}`, {credentials: 'omit'})
        .then(response => response.ok ? response.json() : Promise.reject(new Error('Unavailable')))
        .then(payload => {
            config = payload;
            storageKey = `nexa_cookie_consent_${publicKey}_${config.policyVersion}`;
            const visitorKey = `nexa_cookie_visitor_${publicKey}`;
            visitorId = window.localStorage.getItem(visitorKey) || uuid();
            window.localStorage.setItem(visitorKey, visitorId);
            client.visitorId = visitorId;
            client.config = config;
            resolveReady(config);
            window.dispatchEvent(new CustomEvent('nexa:cookie-ready', {detail: {mode: config.integrationMode}}));
            if (config.shouldDisplay === false || requestedMode === 'existing_banner' || config.integrationMode === 'existing_banner') return;
            if (!window.localStorage.getItem(storageKey)) mount();
        })
        .catch(() => resolveReady(null));

    function normalize(input) {
        const gpc = navigator.globalPrivacyControl === true;
        const supplied = input.categories || {};
        const categories = Object.fromEntries((config.categories || []).map(item => [
            item.key,
            item.isEssential || (gpc && item.key === 'advertising' ? false : Boolean(supplied[item.key])),
        ]));
        let choice = input.choice;
        if (!['accept_all', 'reject_optional', 'custom'].includes(choice)) {
            const optional = (config.categories || []).filter(item => !item.isEssential);
            choice = optional.every(item => categories[item.key]) ? 'accept_all' : optional.every(item => !categories[item.key]) ? 'reject_optional' : 'custom';
        }
        return {choice, categories, policyVersion: config.policyVersion, savedAt: new Date().toISOString()};
    }

    function persist(input) {
        if (!config) return Promise.reject(new Error('Nexa consent configuration is unavailable.'));
        const record = normalize(input);
        const previous = client.getPreferences();
        if (previous && previous.choice === record.choice && JSON.stringify(previous.categories) === JSON.stringify(record.categories) && !input.force) {
            return Promise.resolve(previous);
        }
        window.localStorage.setItem(storageKey, JSON.stringify(record));
        window.dispatchEvent(new CustomEvent('nexa:cookie-consent', {detail: record}));
        const body = new URLSearchParams({
            publicKey, receiptKey: input.receiptKey || uuid(), visitorId, choice: record.choice,
            categories: JSON.stringify(record.categories), pageUrl: location.href, referrerUrl: document.referrer || '',
            locale: navigator.language, regionCode: config.regionCode || '',
            globalPrivacyControl: navigator.globalPrivacyControl === true ? '1' : '0', userAgent: navigator.userAgent,
        });
        return fetch(`${apiUrl}/Nexa/public/cookies/receipts`, {method: 'POST', credentials: 'omit', keepalive: true, body})
            .then(response => response.ok ? record : Promise.reject(new Error('Consent receipt could not be recorded.')));
    }

    function mount() {
        if (document.querySelector('[data-nexa-cookie-banner]')) return;
        const defaults = Object.fromEntries((config.categories || []).map(item => [item.key, item.isEssential || (item.defaultEnabled && navigator.globalPrivacyControl !== true)]));
        const host = document.createElement('section');
        host.dataset.nexaCookieBanner = '';
        host.setAttribute('role', 'dialog');
        host.setAttribute('aria-modal', 'false');
        host.setAttribute('aria-labelledby', 'nexa-cookie-heading');
        host.innerHTML = `<style>
            [data-nexa-cookie-banner]{--nexa-primary:${escape(config.primaryColor)};--nexa-bg:${escape(config.backgroundColor)};--nexa-text:${escape(config.textColor)};position:fixed;z-index:2147483640;${config.position === 'bottom_left' ? 'left:20px;bottom:20px;max-width:460px' : config.position === 'bottom_right' ? 'right:20px;bottom:20px;max-width:460px' : 'left:20px;right:20px;bottom:20px'};background:var(--nexa-bg);color:var(--nexa-text);border:1px solid color-mix(in srgb,var(--nexa-text) 18%,transparent);box-shadow:0 14px 46px rgba(12,31,27,.2);font:14px/1.5 Arial,sans-serif;padding:22px}
            [data-nexa-cookie-banner] *{box-sizing:border-box}[data-nexa-cookie-banner] h2{font-size:20px;line-height:1.25;margin:0 0 8px}[data-nexa-cookie-banner] p{margin:0 0 14px}[data-nexa-cookie-banner] a{color:var(--nexa-primary);font-weight:700}[data-nexa-cookie-actions]{display:flex;flex-wrap:wrap;gap:9px;margin-top:16px}[data-nexa-cookie-banner] button{border:1px solid var(--nexa-primary);cursor:pointer;font:700 13px Arial,sans-serif;padding:10px 14px}[data-nexa-cookie-banner] button[data-primary]{background:var(--nexa-primary);color:#fff}[data-nexa-cookie-banner] button:not([data-primary]){background:transparent;color:var(--nexa-text)}[data-nexa-cookie-settings]{border-top:1px solid color-mix(in srgb,var(--nexa-text) 16%,transparent);display:none;margin-top:14px;padding-top:10px}[data-nexa-cookie-settings].is-open{display:block}[data-nexa-cookie-category]{align-items:flex-start;display:flex;gap:10px;padding:8px 0}[data-nexa-cookie-category] input{height:17px;margin-top:2px;width:17px}[data-nexa-cookie-category] strong,[data-nexa-cookie-category] small{display:block}[data-nexa-cookie-category] small{opacity:.72}@media(max-width:560px){[data-nexa-cookie-banner]{left:10px!important;right:10px!important;bottom:10px!important;max-width:none!important;padding:18px}[data-nexa-cookie-actions] button{flex:1 1 auto}}
        </style><h2 id="nexa-cookie-heading">${escape(config.heading)}</h2><p>${escape(config.message)} ${config.privacyNoticeUrl ? `<a href="${escape(config.privacyNoticeUrl)}" target="_blank" rel="noopener">Privacy notice</a>` : ''}</p>
        <div data-nexa-cookie-settings>${(config.categories || []).map(item => `<label data-nexa-cookie-category><input type="checkbox" data-category="${escape(item.key)}" ${defaults[item.key] ? 'checked' : ''} ${item.isEssential ? 'disabled' : ''}><span><strong>${escape(item.name)}</strong><small>${escape(item.description || '')}${item.isEssential ? ' Always active.' : ''}</small></span></label>`).join('')}</div>
        <div data-nexa-cookie-actions><button type="button" data-primary data-choice="accept_all">Accept all</button>${config.showReject ? '<button type="button" data-choice="reject_optional">Reject optional</button>' : ''}<button type="button" data-manage>Manage choices</button><button type="button" data-choice="custom" hidden>Save choices</button></div>`;
        document.body.append(host);
        host.querySelector('[data-manage]').addEventListener('click', event => {
            host.querySelector('[data-nexa-cookie-settings]').classList.add('is-open');
            event.currentTarget.hidden = true;
            host.querySelector('[data-choice="custom"]').hidden = false;
        });
        host.querySelectorAll('[data-choice]').forEach(button => button.addEventListener('click', () => {
            const categories = {...defaults};
            if (button.dataset.choice === 'accept_all') Object.keys(categories).forEach(key => { categories[key] = true; });
            if (button.dataset.choice === 'reject_optional') (config.categories || []).forEach(item => { categories[item.key] = item.isEssential; });
            if (button.dataset.choice === 'custom') host.querySelectorAll('[data-category]').forEach(input => { categories[input.dataset.category] = input.disabled || input.checked; });
            persist({choice: button.dataset.choice, categories}).catch(() => {});
            host.remove();
        }));
    }
})();
