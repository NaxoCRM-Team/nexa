{{#if isUnavailable}}
<span class="nexa-account-contact-count is-unavailable" title="Contact count is temporarily unavailable">Unavailable</span>
{{else}}
    {{#if isLoading}}
    <span class="nexa-account-contact-count is-loading" aria-label="Loading contact count">
        <span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span>
        Loading
    </span>
    {{else}}
    <a class="nexa-account-contact-count" href="{{contactsUrl}}"
       aria-label="View {{count}} contacts for {{accountName}}" title="View contacts for {{accountName}}">
        <span class="fas fa-users" aria-hidden="true"></span>
        <span>View contacts</span>
        <strong>{{count}}</strong>
    </a>
    {{/if}}
{{/if}}
