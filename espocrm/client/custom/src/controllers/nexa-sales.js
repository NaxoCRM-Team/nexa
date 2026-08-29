define('custom:controllers/nexa-sales', ['controller'], Dep => class extends Dep {
    actionIndex() { this.actionPipelines(); }
    actionPipelines() { this.open('pipelines'); }
    actionForecasts() { this.open('forecasts'); }
    actionProducts() { this.open('products'); }
    open(section) { this.main('custom:views/sales/workspace', {section}, view => view.render()); }
});
