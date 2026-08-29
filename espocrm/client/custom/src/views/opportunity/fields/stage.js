define('custom:views/opportunity/fields/stage', ['crm:views/opportunity/fields/stage'], Dep => class extends Dep {
    setup() {
        super.setup();
        this.salesStages = [];

        // List and detail views only need the native Opportunity stage label. Loading the
        // complete sales workspace for every rendered row creates duplicate API requests.
        if (this.mode === 'edit') {
            this.listenTo(this.model, 'change:pipelineId', () => this.renderPipelineStages());
            this.loadPipelineStages();
        }
    }

    async loadPipelineStages() {
        try {
            const data = await Espo.Ajax.getRequest('Nexa/sales/workspace', {range: 'all'});
            this.salesStages = data.stages || [];
            if (this.isRendered()) this.renderPipelineStages();
        } catch (error) {
            // The native stage field remains available when sales configuration cannot load.
        }
    }

    afterRender() {
        super.afterRender();
        this.renderPipelineStages();
    }

    renderPipelineStages() {
        if (this.mode !== 'edit' || !this.element || !this.salesStages.length) return;
        const pipelineId = this.model.get('pipelineId');
        const stages = this.salesStages.filter(item => item.pipelineId === pipelineId);
        if (!stages.length) return;
        const current = this.model.get(this.name) || stages[0].name;
        const select = document.createElement('select');
        select.className = 'form-control'; select.name = this.name;
        stages.forEach(stage => select.add(new Option(stage.name, stage.name, false, stage.name === current)));
        this.element.querySelector('select, input')?.replaceWith(select);
        if (!stages.some(stage => stage.name === current)) this.model.set(this.name, stages[0].name);
        select.addEventListener('change', () => this.model.set(this.name, select.value));
    }
});
