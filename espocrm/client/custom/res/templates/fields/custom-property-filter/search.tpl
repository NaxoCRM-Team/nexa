<div class="nexa-custom-property-filter">
    {{#if empty}}
    <p class="text-muted">No filterable custom properties are available.</p>
    {{else}}
    <label>
        <span class="sr-only">Custom property</span>
        <select class="form-control" data-custom-property>
            <option value="">Choose a custom property</option>
            {{#each properties}}<option value="{{key}}"{{#if selected}} selected{{/if}}>{{label}}</option>{{/each}}
        </select>
    </label>
    <label>
        <span class="sr-only">Filter condition</span>
        <select class="form-control" data-custom-operator disabled></select>
    </label>
    <span data-custom-value-host></span>
    {{/if}}
</div>
