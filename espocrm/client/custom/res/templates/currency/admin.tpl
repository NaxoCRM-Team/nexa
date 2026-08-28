<main class="nexa-currency-admin" aria-labelledby="nexa-currency-title">
    <header class="nexa-currency-header">
        <div><p>Administration / Workspace settings</p><h1 id="nexa-currency-title">Currency</h1><span>Choose how this workspace prices products, records opportunities and reports revenue.</span></div>
        <a class="btn btn-default" href="#Admin"><span class="fas fa-arrow-left"></span>Administration</a>
    </header>
    <section data-currency-state="loading" class="nexa-currency-state"><span class="fas fa-circle-notch fa-spin"></span><p>Loading currency settings...</p></section>
    <section data-currency-state="error" class="nexa-currency-state" hidden><span class="fas fa-exclamation-circle"></span><p>Currency settings could not be loaded.</p></section>
    <form data-currency-form data-currency-state="ready" hidden>
        <section class="nexa-currency-panel">
            <div class="nexa-currency-panel-heading"><div><h2>Workspace currencies</h2><p>Search the complete supported currency catalogue and add every currency this tenant uses.</p></div><label class="nexa-currency-search"><span class="fas fa-search"></span><input class="form-control" type="search" data-currency-search placeholder="Search all currencies" autocomplete="off" aria-label="Search all supported currencies"></label></div>
            <div class="nexa-currency-results" data-currency-results hidden></div>
            <div class="nexa-enabled-currencies" data-enabled-currencies></div>
        </section>
        <section class="nexa-rate-settings">
            <label><span>Exchange-rate source</span><small>Use daily reference rates automatically, or choose manual override for an agreed operational rate.</small><select class="form-control" name="rateMode"><option value="automatic">Automatic reference rates (recommended)</option><option value="manual">Manual override</option></select></label>
            <div class="nexa-rate-status"><span class="fas fa-sync-alt"></span><div><strong data-rate-status-title>Automatic reference rates</strong><small data-rate-status-copy>Rates will be fetched securely when settings are saved.</small></div><button class="btn btn-default" type="button" data-action="refresh-rates"><span class="fas fa-sync-alt"></span>Refresh latest rates</button></div>
        </section>
        <section class="nexa-currency-summary" style="margin-top:20px">
            <label><span>Base reporting currency</span><small>Forecasts and dashboards are converted into this currency. Add a currency above before selecting it here.</small><select class="form-control" name="baseCurrency" required></select></label>
            <label><span>Default transaction currency</span><small>Preselected for new opportunities and products. Only enabled workspace currencies appear here.</small><select class="form-control" name="defaultCurrency" required></select></label>
        </section>
        <aside class="nexa-currency-guidance"><span class="fas fa-info-circle"></span><p data-rate-guidance></p></aside>
        <footer><button class="btn btn-primary" type="submit"><span class="fas fa-save"></span>Save currency settings</button></footer>
    </form>
</main>
