define('custom:controllers/nexa-landing-pages', ['controller'], Dep => class extends Dep { actionIndex() { this.main('custom:views/landing-page/workspace', {}, view => view.render()); } });
