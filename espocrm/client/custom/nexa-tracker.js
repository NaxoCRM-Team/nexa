(() => {
    'use strict';

    const script = document.currentScript;
    const publicKey = script?.dataset.nexaSourceKey || '';
    if (!/^[a-f0-9]{48}$/.test(publicKey) || window.NexaTracking?.publicKey === publicKey) return;
    const source = new URL(script.src, window.location.href);
    const marker = '/client/custom/nexa-tracker.js';
    const root = source.pathname.endsWith(marker) ? source.pathname.slice(0, -marker.length) : '';
    const endpoint = `${source.origin}${root}/api/v1/Nexa/public/events/${publicKey}`;
    const mode = script.dataset.nexaConsentMode || 'managed';
    const uuid = () => crypto.randomUUID?.() || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, value => {
        const random = Math.random() * 16 | 0;
        return (value === 'x' ? random : (random & 3 | 8)).toString(16);
    });
    const visitorStorage = `nexa_tracking_visitor_${publicKey}`;
    const sessionStorage = `nexa_tracking_session_${publicKey}`;
    let visitorKey = window.localStorage.getItem(visitorStorage) || uuid();
    let sessionKey = window.sessionStorage.getItem(sessionStorage) || uuid();
    let externalConsent = null;
    window.localStorage.setItem(visitorStorage, visitorKey);
    window.sessionStorage.setItem(sessionStorage, sessionKey);

    const client = window.NexaTracking = {
        publicKey,
        mode,
        setConsent(value) { externalConsent = value || null; },
        track(eventType, properties = {}, options = {}) {
            return send(eventType, properties, options);
        },
    };

    async function consentFor(eventType, options) {
        if (mode === 'necessary_only') return {};
        if (mode === 'external') {
            const supplied = options.consent || externalConsent;
            if (!supplied?.categories || !supplied?.provider || !supplied?.policyVersion) return null;
            return {
                consent: supplied.categories,
                consentEvidence: {provider: supplied.provider, policyVersion: supplied.policyVersion},
            };
        }
        const consentClient = window.NexaConsent;
        if (!consentClient?.ready) return null;
        await consentClient.ready;
        const preferences = consentClient.getPreferences?.();
        if (!preferences) return null;
        visitorKey = consentClient.visitorId || visitorKey;
        return {consent: Object.fromEntries(Object.entries(preferences.categories || {}).map(([key, value]) => [key, value ? 'granted' : 'denied']))};
    }

    async function send(eventType, properties, options) {
        const consent = await consentFor(eventType, options);
        if (mode !== 'necessary_only' && !consent) return {suppressed: true, reason: 'consent_unavailable'};
        const body = {
            eventType,
            eventVersion: 1,
            idempotencyKey: options.idempotencyKey || uuid(),
            correlationId: options.correlationId || undefined,
            visitorKey,
            sessionKey,
            pageUrl: options.pageUrl || window.location.href,
            referrerUrl: options.referrerUrl ?? document.referrer,
            occurredAt: options.occurredAt || new Date().toISOString(),
            properties: properties || {},
            ...consent,
        };
        const response = await fetch(endpoint, {
            method: 'POST', credentials: 'omit', keepalive: true,
            headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body),
        });
        if (!response.ok) throw new Error(`Nexa event collection failed (${response.status}).`);
        return response.json();
    }

    const pageProperties = () => ({title: document.title, path: location.pathname, query: location.search || null});
    const start = () => send('page.viewed', pageProperties(), {}).catch(() => {});
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
    document.addEventListener('click', event => {
        const link = event.target.closest?.('a[href]');
        if (!link) return;
        send('link.clicked', {text: (link.textContent || '').trim().slice(0, 200), href: link.href}, {}).catch(() => {});
    }, {capture: true});
})();
