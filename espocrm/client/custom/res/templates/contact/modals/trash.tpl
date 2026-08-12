<div class="nexa-contact-trash">
    <p class="text-muted">Deleted contacts remain available here for two months. Only tenant administrators can restore them.</p>
    {{#if hasRecords}}
    <div class="nexa-trash-list" role="group" aria-label="Deleted contacts">
        {{#each records}}
        <label class="nexa-trash-row">
            <input type="checkbox" data-name="trashContact" value="{{id}}">
            <span class="nexa-trash-contact"><strong>{{name}}</strong><span>{{emailAddress}}</span></span>
            <span class="nexa-trash-date">Deleted {{deletedAt}}</span>
        </label>
        {{/each}}
    </div>
    {{else}}
    <div class="nexa-trash-empty"><span class="far fa-trash-alt" aria-hidden="true"></span><strong>No deleted contacts</strong><span>Deleted contacts will appear here during their recovery window.</span></div>
    {{/if}}
</div>
