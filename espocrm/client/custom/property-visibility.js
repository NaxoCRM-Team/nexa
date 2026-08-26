(function () {
    'use strict';

    const supported = new Set(['Contact', 'Account']);
    const cache = new Map();
    let activeEntity = null;
    let hiddenFields = new Set();
    let applyTimer = null;

    function routeEntity() {
        const value = `${window.location.pathname}/${window.location.hash}`;
        const match = value.match(/(?:^|\/|#)(Contact|Account)(?:\/|$|\?|#)/);
        return match && supported.has(match[1]) ? match[1] : null;
    }

    function restore() {
        document.querySelectorAll('.nexa-property-hidden-by-tenant').forEach(element => {
            element.classList.remove('nexa-property-hidden-by-tenant');
            element.removeAttribute('data-nexa-property-hidden');
        });
    }

    function fieldContainer(element) {
        return element.closest('.cell, .field, .form-group, th, td, li') || element;
    }

    function applyVisibility() {
        restore();
        if (!activeEntity || !hiddenFields.size) return;
        document.querySelectorAll('[data-name], [data-field], [data-nexa-property]').forEach(element => {
            const key = element.dataset.name || element.dataset.field || element.dataset.nexaProperty;
            if (!hiddenFields.has(key)) return;
            const container = fieldContainer(element);
            container.classList.add('nexa-property-hidden-by-tenant');
            container.dataset.nexaPropertyHidden = key;
        });
    }

    function scheduleApply() {
        window.clearTimeout(applyTimer);
        applyTimer = window.setTimeout(applyVisibility, 40);
    }

    async function load(entityType, force = false) {
        activeEntity = entityType;
        hiddenFields = new Set();
        restore();
        if (!entityType || !window.Espo?.Ajax) return;
        try {
            if (force || !cache.has(entityType)) {
                cache.set(entityType, await Espo.Ajax.getRequest('Nexa/customization/definitions', {entityType}));
            }
            const definitions = cache.get(entityType);
            hiddenFields = new Set([...(definitions.standardFields || []), ...(definitions.fields || [])]
                .filter(field => field.is_enabled === false)
                .map(field => field.field_key));
            scheduleApply();
        } catch (error) {
            // A visibility preference must never prevent the underlying CRM screen from loading.
            console.warn('Nexa property visibility could not be loaded.', error);
        }
    }

    function handleRoute(force = false) {
        const entityType = routeEntity();
        if (!force && entityType === activeEntity) {
            scheduleApply();
            return;
        }
        load(entityType, force);
    }

    function start() {
        const observer = new MutationObserver(scheduleApply);
        observer.observe(document.body, {childList: true, subtree: true});
        window.addEventListener('hashchange', () => handleRoute());
        window.addEventListener('popstate', () => handleRoute());
        document.addEventListener('nexa:property-visibility-changed', event => {
            const entityType = event.detail?.entityType;
            if (entityType) cache.delete(entityType);
            handleRoute(true);
        });
        window.setInterval(() => handleRoute(), 750);
        handleRoute(true);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
}());
