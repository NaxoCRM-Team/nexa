{{#if hasLocation}}
<span class="nexa-contact-location" title="{{country}}">
    {{#if flagUrl}}<img class="nexa-contact-location-flag" src="{{flagUrl}}" alt="{{flagAlt}}">{{/if}}
    <span class="nexa-contact-location-text">{{location}}</span>
</span>
{{else}}
<span class="text-muted" aria-label="Location not provided">&mdash;</span>
{{/if}}
