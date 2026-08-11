{{#if hasStatus}}
<span class="nexa-lead-status nexa-lead-status--{{statusClass}}" title="{{valueTranslated}}">{{valueTranslated}}</span>
{{else}}
<span class="text-muted" aria-label="Lead status not provided">&mdash;</span>
{{/if}}
