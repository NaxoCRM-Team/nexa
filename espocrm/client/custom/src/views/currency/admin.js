define('custom:views/currency/admin', ['view'], Dep => class extends Dep {
    template = 'custom:currency/admin';

    events = {
        'submit [data-currency-form]': 'save',
        'click [data-action="add-currency"]': 'addCurrency',
        'click [data-action="remove-currency"]': 'removeCurrency',
        'input [data-currency-search]': 'filterCatalogue',
        'focus [data-currency-search]': 'showCatalogue',
        'change [name="baseCurrency"]': 'changeBase',
        'change [name="defaultCurrency"]': 'changeDefault',
        'change [name="rateMode"]': 'changeRateMode',
        'click [data-action="refresh-rates"]': 'refreshRates',
    };

    setup() {
        this.setPageTitle('Currency');
        this.dataSet = null;
    }

    afterRender() {
        super.afterRender();
        this.load();
    }

    async load() {
        this.setState('loading');
        try {
            this.dataSet = await Espo.Ajax.getRequest('Nexa/currency/settings');
            this.renderSettings();
            this.setState('ready');
        } catch (error) {
            this.setState('error');
            Espo.Ui.error(error?.message || 'Currency settings could not be loaded.');
        }
    }

    setState(state) {
        this.element?.querySelectorAll('[data-currency-state]').forEach(node => {
            node.hidden = node.dataset.currencyState !== state;
            node.style.display = node.hidden ? 'none' : '';
        });
    }

    label(code) {
        const name = this.getLanguage().translate(code, 'names', 'Currency');
        return name && name !== code ? `${code} - ${name}` : code;
    }

    escape(value) {
        const node = document.createElement('div'); node.textContent = String(value ?? ''); return node.innerHTML;
    }

    renderSettings() {
        const data = this.dataSet;
        const enabled = data.enabledCurrencies || ['USD'];
        const options = enabled.map(code => `<option value="${this.escape(code)}">${this.escape(this.label(code))}</option>`).join('');
        const base = this.element.querySelector('[name="baseCurrency"]');
        const defaultCurrency = this.element.querySelector('[name="defaultCurrency"]');
        base.innerHTML = options; defaultCurrency.innerHTML = options;
        base.value = data.baseCurrency; defaultCurrency.value = data.defaultCurrency;
        this.element.querySelector('[name="rateMode"]').value = data.rateMode || 'automatic';
        this.element.querySelector('[data-enabled-currencies]').innerHTML = enabled.map(code => this.currencyCard(code)).join('');
        const automatic = (data.rateMode || 'automatic') === 'automatic';
        this.element.querySelector('[data-rate-guidance]').textContent = automatic
            ? `Reference rates show how many ${data.baseCurrency} equal 1 unit of each enabled currency. Existing records retain their original transaction currency.`
            : `Enter how many ${data.baseCurrency} equal 1 unit of each enabled currency. Existing records retain their original transaction currency.`;
        const effective = data.ratesEffectiveDate ? ` Effective ${data.ratesEffectiveDate}.` : '';
        this.element.querySelector('[data-rate-status-title]').textContent = automatic ? 'Automatic reference rates' : 'Manual rate override';
        this.element.querySelector('[data-rate-status-copy]').textContent = automatic
            ? `${data.rateProvider === 'frankfurter' ? 'Frankfurter central-bank reference data.' : 'Rates will be fetched securely when settings are saved.'}${effective}`
            : 'Rates are entered and maintained by a tenant administrator.';
        this.element.querySelector('[data-action="refresh-rates"]').hidden = !automatic;
        this.renderCatalogue();
        const results = this.element.querySelector('[data-currency-results]');
        results.hidden = true;
        results.style.display = 'none';
    }

    currencyCard(code) {
        const base = this.dataSet.baseCurrency;
        const protectedCode = code === base || code === this.dataSet.defaultCurrency;
        const provided = Number(this.dataSet.rates?.[code]);
        const rate = code === base ? 1 : (Number.isFinite(provided) && provided > 0 ? provided : '');
        const automatic = (this.dataSet.rateMode || 'automatic') === 'automatic';
        return `<article class="nexa-currency-card" data-currency-code="${this.escape(code)}">
            <div class="nexa-currency-code"><strong>${this.escape(code)}</strong><span>${this.escape(this.label(code).replace(`${code} - `, ''))}</span></div>
            <label><span>Exchange rate</span><span class="nexa-rate-input"><b>1 ${this.escape(code)} =</b><input class="form-control" name="rate-${this.escape(code)}" type="number" min="0.0000000001" step="any" value="${rate}" required ${code === base || automatic ? 'readonly' : ''}><b>${this.escape(base)}</b></span></label>
            <button class="btn btn-icon" type="button" data-action="remove-currency" data-code="${this.escape(code)}" ${protectedCode ? 'disabled' : ''} title="${protectedCode ? 'Change the base or default currency before removing this currency.' : 'Remove currency'}"><span class="fas ${protectedCode ? 'fa-lock' : 'fa-times'}"></span></button>
        </article>`;
    }

    renderCatalogue(query = '') {
        const enabled = new Set(this.dataSet.enabledCurrencies || []);
        const term = query.trim().toLowerCase();
        const matches = (this.dataSet.catalogue || []).filter(code => !enabled.has(code) && (!term || this.label(code).toLowerCase().includes(term)));
        const host = this.element.querySelector('[data-currency-results]');
        host.innerHTML = matches.map(code => `<button type="button" data-action="add-currency" data-code="${this.escape(code)}"><strong>${this.escape(code)}</strong><span>${this.escape(this.label(code).replace(`${code} - `, ''))}</span><i class="fas fa-plus"></i></button>`).join('') || `<p>${term ? 'No matching currencies.' : 'Type a currency name or code to add it.'}</p>`;
        host.hidden = false;
        host.style.display = '';
        host.style.maxHeight = '320px';
        host.style.overflowY = 'auto';
    }

    filterCatalogue(event) {
        this.renderCatalogue(event.currentTarget.value);
    }

    showCatalogue(event) {
        this.renderCatalogue(event.currentTarget.value);
    }

    async addCurrency(event) {
        const code = event.currentTarget.dataset.code;
        if (!code || this.dataSet.enabledCurrencies.includes(code)) return;
        this.captureRates();
        this.dataSet.enabledCurrencies.push(code);
        this.dataSet.rates[code] = null;
        this.element.querySelector('[data-currency-search]').value = '';
        this.renderSettings();
        if ((this.dataSet.rateMode || 'automatic') === 'automatic') {
            await this.refreshRates(null, true);
        } else {
            this.element.querySelector(`[name="rate-${code}"]`)?.focus();
        }
    }

    removeCurrency(event) {
        const code = event.currentTarget.dataset.code;
        if (code === this.dataSet.baseCurrency || code === this.dataSet.defaultCurrency) return;
        this.captureRates();
        this.dataSet.enabledCurrencies = this.dataSet.enabledCurrencies.filter(item => item !== code);
        delete this.dataSet.rates[code];
        this.renderSettings();
    }

    captureRates() {
        (this.dataSet.enabledCurrencies || []).forEach(code => {
            const input = this.element.querySelector(`[name="rate-${code}"]`);
            if (input) this.dataSet.rates[code] = Number(input.value);
        });
    }

    async changeBase(event) {
        this.captureRates();
        const previousBase = this.dataSet.baseCurrency;
        const nextBase = event.currentTarget.value;
        const previousDefault = this.dataSet.defaultCurrency;

        this.dataSet.baseCurrency = nextBase;
        if (previousDefault === previousBase) this.dataSet.defaultCurrency = nextBase;

        if ((this.dataSet.rateMode || 'automatic') === 'automatic') {
            try {
                await this.refreshRates(null, true, true);
            } catch (error) {
                this.dataSet.baseCurrency = previousBase;
                this.dataSet.defaultCurrency = previousDefault;
                this.renderSettings();
            }
            return;
        }

        const divisor = Number(this.dataSet.rates[nextBase]);

        if (!Number.isFinite(divisor) || divisor <= 0) {
            event.currentTarget.value = previousBase;
            Espo.Ui.error(`Enter the ${nextBase} exchange rate before making it the base currency.`);
            this.element.querySelector(`[name="rate-${nextBase}"]`)?.focus();
            return;
        }

        this.dataSet.enabledCurrencies.forEach(code => {
            this.dataSet.rates[code] = Number((Number(this.dataSet.rates[code]) / divisor).toFixed(10));
        });
        this.dataSet.rates[nextBase] = 1;
        this.renderSettings();
    }

    changeDefault(event) {
        this.captureRates();
        this.dataSet.defaultCurrency = event.currentTarget.value;
        this.renderSettings();
    }

    changeRateMode(event) {
        this.captureRates();
        this.dataSet.rateMode = event.currentTarget.value;
        this.renderSettings();
        if (this.dataSet.rateMode === 'automatic') this.refreshRates(null, true);
    }

    async refreshRates(event = null, quiet = false, rethrow = false) {
        event?.preventDefault();
        const button = this.element.querySelector('[data-action="refresh-rates"]');
        if (button) button.disabled = true;
        try {
            const snapshot = await Espo.Ajax.postRequest('Nexa/currency/rates/preview', {
                baseCurrency: this.dataSet.baseCurrency,
                enabledCurrencies: this.dataSet.enabledCurrencies,
            });
            this.dataSet.rates = snapshot.rates;
            this.dataSet.rateProvider = snapshot.provider;
            this.dataSet.ratesEffectiveDate = snapshot.effectiveDate;
            this.dataSet.rateMode = 'automatic';
            this.renderSettings();
            if (!quiet) Espo.Ui.success('Latest reference rates loaded. Save to apply them to this workspace.');
            return snapshot;
        } catch (error) {
            Espo.Ui.error(error?.message || 'Latest exchange rates are temporarily unavailable.');
            if (rethrow) throw error;
            return null;
        } finally {
            if (button) button.disabled = false;
        }
    }

    async save(event) {
        event.preventDefault();
        this.captureRates();
        const button = event.currentTarget.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            this.dataSet = await Espo.Ajax.putRequest('Nexa/currency/settings', {
                baseCurrency: this.dataSet.baseCurrency,
                defaultCurrency: this.dataSet.defaultCurrency,
                enabledCurrencies: this.dataSet.enabledCurrencies,
                rates: this.dataSet.rates,
                rateMode: this.dataSet.rateMode || 'automatic',
            });
            this.getConfig().set('currencyList', this.dataSet.enabledCurrencies);
            this.getConfig().set('defaultCurrency', this.dataSet.defaultCurrency);
            this.getConfig().set('baseCurrency', this.dataSet.baseCurrency);
            this.getConfig().set('currencyRates', this.dataSet.rates);
            this.renderSettings();
            Espo.Ui.success('Workspace currency settings saved.');
        } catch (error) {
            Espo.Ui.error(error?.message || 'Currency settings could not be saved.');
        } finally {
            button.disabled = false;
        }
    }
});
