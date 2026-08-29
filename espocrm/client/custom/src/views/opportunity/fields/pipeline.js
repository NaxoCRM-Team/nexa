define('custom:views/opportunity/fields/pipeline', ['views/fields/varchar'], Dep => class extends Dep {
    setup() {
        super.setup();
        this.pipelineMap = {};
        this.loadPipelines();
    }

    async loadPipelines() {
        try {
            const data = await Espo.Ajax.getRequest('Nexa/sales/workspace', {range: 'quarter'});
            (data.pipelines || []).forEach(item => { this.pipelineMap[item.id] = item.name; });
            if (!this.model.get(this.name) && data.defaultPipelineId) this.model.set(this.name, data.defaultPipelineId);
            if (this.isRendered()) this.reRender();
        } catch (error) {
            // The native record remains usable if optional sales configuration is unavailable.
        }
    }

    getValueForDisplay() {
        const id = this.model.get(this.name);
        return this.pipelineMap[id] || (id ? 'Sales pipeline' : 'Not selected');
    }

    afterRender() {
        super.afterRender();
        if (this.mode !== 'edit' || !this.element || !Object.keys(this.pipelineMap).length) return;
        const current = this.model.get(this.name) || '';
        const select = document.createElement('select');
        select.className = 'form-control';
        select.name = this.name;
        Object.entries(this.pipelineMap).forEach(([id, name]) => select.add(new Option(name, id, false, id === current)));
        const input = this.element.querySelector('input, select');
        input?.replaceWith(select);
        select.addEventListener('change', () => this.model.set(this.name, select.value));
    }
});
