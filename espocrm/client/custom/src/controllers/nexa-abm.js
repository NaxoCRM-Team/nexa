define('custom:controllers/nexa-abm',['controller'],Dep=>class extends Dep{
    actionIndex(){this.main('custom:views/abm/workspace',{},view=>view.render());}
});
