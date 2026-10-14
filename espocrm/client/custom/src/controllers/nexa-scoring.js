define('custom:controllers/nexa-scoring',['controller'],Dep=>class extends Dep {
    actionIndex() {this.main('custom:views/scoring/workspace',{},view=>view.render());}
});
