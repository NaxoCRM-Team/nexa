{{#if hasLocation}}
<span class="nexa-account-location" title="{{country}}">
    {{#if flagUrl}}<img class="nexa-account-location-flag" src="{{flagUrl}}" alt="{{flagAlt}}">{{/if}}
    <span class="nexa-account-location-text">{{location}}</span>
</span>
{{else}}
<span class="text-muted" aria-label="Location not provided">&mdash;</span>
{{/if}}
