define('custom:controllers/nexa-personalization',['controller'],Dep=>class extends Dep{
    actionIndex(){this.main('custom:views/marketing-email/personalization-history',{},view=>view.render());}
});
